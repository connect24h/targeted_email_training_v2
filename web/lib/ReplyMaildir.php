<?php declare(strict_types=1);

/**
 * 返信者(Maildir パース)の一覧計算。
 *
 * 元は api/logs.php 内にあり、ログ管理の「返信者」タブからのみ使われていた。
 * レポートの Excel(api/report.php の export_xlsx)にもシートとして載せることになり、
 * 両APIから同一ロジックを呼ぶために切り出した(2026-08-31)。
 * api/logs.php を require すると末尾のディスパッチが走って exit するため、
 * 共有は lib/ 経由でなければならない(GeoIpCache.php / TrainingLogRows.php と同じ流儀)。
 *
 * ★権限について(重要)
 * この機能は DB ではなくメールサーバの Maildir を直接読む。メールにはテナントを
 * 示す情報がないため全テナントのメールが混在し、ログ管理では superadmin 限定に
 * している(logs_min_role_for_action)。campaign_id で送信元アドレス一致に絞れるが、
 * その絞り込みは assert_campaign_owned() を通しておらずテナント所有を検証しない。
 * したがって呼び出し側は superadmin であることを必ず確認すること。
 * レポートExcel(viewer で出力可)は reply_maildir_export_allowed() で判定し、
 * superadmin 以外には中身を出さない。
 */

/* ============================================================
 * 返信者(Maildir パース) — v1 mail_replies.php 相当。
 * 訓練メール送信元アカウントの Maildir を直接読み、受信メール(=返信含む)を
 * 一覧・本文表示する。DB 正規化ログではなく実ファイル参照のため superadmin 限定。
 * メールサーバは参照のみ(書き込み・設定変更は一切しない)。
 * ============================================================ */

/** /etc/postfix/virtual_mailbox_maps を読み、user => email のマップを返す。 */
function mailbox_email_mapping(): array
{
    $mapping = [];
    $vmapFile = '/etc/postfix/virtual_mailbox_maps';
    if (is_file($vmapFile) && is_readable($vmapFile)) {
        $lines = file($vmapFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) >= 2) {
                $email = $parts[0];
                $user = str_replace('/Maildir', '', rtrim($parts[1], '/'));
                $mapping[$user] = $email;
            }
        }
    }
    return $mapping;
}

/** /home 配下および /root の Maildir を検出し、[user => ['maildir','email']] を返す。 */
function discover_maildirs(): array
{
    $emailMapping = mailbox_email_mapping();
    $maildirs = [];
    foreach (glob('/home/*/Maildir', GLOB_ONLYDIR) ?: [] as $dir) {
        $user = basename(dirname($dir));
        // 読めないディレクトリはスキップ(mcp 等)。
        if (!is_readable($dir . '/new') && !is_readable($dir . '/cur')) { continue; }
        $email = $emailMapping[$user] ?? ($user . '@cojp.online');
        $maildirs[$user] = ['maildir' => $dir, 'email' => $email];
    }
    if (is_dir('/root/Maildir') && (is_readable('/root/Maildir/new') || is_readable('/root/Maildir/cur'))) {
        $maildirs['root'] = ['maildir' => '/root/Maildir', 'email' => 'root@cojp.online'];
    }
    ksort($maildirs);
    return $maildirs;
}

/** MIME エンコードされたヘッダー文字列(=?charset?B/Q?...?=)を UTF-8 にデコードする。 */
function decode_mime_header(string $string): string
{
    $decoded = preg_replace_callback('/=\?([^?]+)\?([BQbq])\?([^?]*)\?=/', function ($m) {
        $charset = $m[1];
        $encoding = strtoupper($m[2]);
        $text = $m[3];
        if ($encoding === 'B') {
            $decodedText = base64_decode($text);
        } elseif ($encoding === 'Q') {
            $decodedText = quoted_printable_decode(str_replace('_', ' ', $text));
        } else {
            $decodedText = $text;
        }
        if ($decodedText !== false && strtoupper($charset) !== 'UTF-8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $decodedText);
            if ($converted !== false) { return $converted; }
        }
        return $decodedText !== false ? $decodedText : $text;
    }, $string);
    return $decoded !== null ? $decoded : $string;
}

/** メールファイルからヘッダー(date/from/to/subject/添付有無)を解析する。 */
function maildir_parse_headers(string $filepath): ?array
{
    $content = @file_get_contents($filepath, false, null, 0, 256 * 1024); // 先頭 256KB でヘッダーは十分
    if ($content === false) { return null; }

    $headerEnd = strpos($content, "\r\n\r\n");
    if ($headerEnd === false) { $headerEnd = strpos($content, "\n\n"); }
    if ($headerEnd === false) { return null; }
    $headerSection = substr($content, 0, $headerEnd);

    $headers = [];
    $curName = '';
    $curVal = '';
    foreach (preg_split('/\r?\n/', $headerSection) as $line) {
        if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $mm)) {
            if ($curName !== '') { $headers[strtolower($curName)] = trim($curVal); }
            $curName = $mm[1];
            $curVal = $mm[2];
        } elseif (preg_match('/^\s+(.*)$/', $line, $mm)) {
            $curVal .= ' ' . trim($mm[1]);
        }
    }
    if ($curName !== '') { $headers[strtolower($curName)] = trim($curVal); }

    $res = [
        'date' => '', 'from' => '', 'from_email' => '', 'to' => '',
        'subject' => '', 'has_attachment' => false, 'filename' => basename($filepath),
        'unreadable' => false,
    ];
    if (isset($headers['date'])) {
        $ts = strtotime($headers['date']);
        $res['date'] = $ts !== false ? date('Y-m-d H:i:s', $ts) : $headers['date'];
    }
    if (isset($headers['from'])) {
        $from = decode_mime_header($headers['from']);
        $res['from'] = $from;
        if (preg_match('/<([^>]+)>/', $from, $mm)) {
            $res['from_email'] = $mm[1];
        } elseif (preg_match('/([^\s<]+@[^\s>]+)/', $from, $mm)) {
            $res['from_email'] = $mm[1];
        }
    }
    if (isset($headers['to']))      { $res['to'] = decode_mime_header($headers['to']); }
    if (isset($headers['subject'])) { $res['subject'] = decode_mime_header($headers['subject']); }
    if (isset($headers['content-type']) && stripos($headers['content-type'], 'multipart/mixed') !== false) {
        $res['has_attachment'] = true;
    }
    if (isset($headers['x-ms-has-attach']) && strtolower(trim($headers['x-ms-has-attach'])) === 'yes') {
        $res['has_attachment'] = true;
    }
    return $res;
}

/** Maildir の new/cur からメール一覧を取得する。
 *
 * 読めないファイルを黙って捨てない。Postfix virtual(8) は配送時に 0600 で
 * ファイルを作るため、www-data から読めないメールが混ざりうる。以前これを
 * スキップしていたせいで「サーバには届いているのに一覧に出ない」状態になり、
 * 返信の見落としに直結した。読めない場合はプレースホルダ行として残し、
 * 画面側で権限エラーと分かるようにする。
 */
function maildir_list(string $maildirPath): array
{
    $mails = [];
    foreach (['new', 'cur'] as $dir) {
        $path = $maildirPath . '/' . $dir;
        if (!is_dir($path)) { continue; }
        $files = @scandir($path);
        if ($files === false) { continue; }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') { continue; }
            $filepath = $path . '/' . $file;
            if (!is_file($filepath)) { continue; }
            $mail = maildir_parse_headers($filepath);
            if ($mail === null) {
                $mail = maildir_unreadable_entry($filepath);
            }
            $mail['status'] = $dir;
            $mails[] = $mail;
        }
    }
    return $mails;
}

/** 読み取れなかったメールを一覧に残すためのプレースホルダを作る。
 *
 * 日時はファイルの更新時刻で代用する (ヘッダーが読めないため)。
 */
function maildir_unreadable_entry(string $filepath): array
{
    $mtime = @filemtime($filepath);
    $perm = @fileperms($filepath);
    $mode = $perm !== false ? substr(sprintf('%o', $perm), -4) : '????';
    return [
        // 一覧のソートは date の文字列比較なので、正常系と同じ書式に揃える。
        'date' => $mtime !== false ? date('Y-m-d H:i:s', $mtime) : '',
        'from' => '(読み取り不可)',
        'from_email' => '',
        'to' => '',
        'subject' => '(権限不足でヘッダーを読めません: mode ' . $mode . ')',
        'has_attachment' => false,
        'filename' => basename($filepath),
        'unreadable' => true,
    ];
}

/**
 * 返信者一覧を計算する(list/csv 共通)。$_GET の sender/start_date/end_date/q でフィルタ。
 * 戻り値: ['mails' => [...], 'counts' => [...], 'accounts' => [...]]。
 */
/**
 * ?campaign_id 指定時、そのキャンペーンの送信元アドレス群(小文字)を返す。
 * campaigns.from_address と campaign_contents.from_address の両方を集める
 * (コンテンツ別に送信元が異なるキャンペーンがあるため)。未指定なら null(=絞らない)。
 * 返信者タブは superadmin 限定・全テナント横断のためテナント検証はしない。
 */
function reply_maildir_campaign_from_addresses(): ?array
{
    if (!isset($_GET['campaign_id']) || $_GET['campaign_id'] === '') { return null; }
    $cid = (int) $_GET['campaign_id'];
    if ($cid < 1) { json_error('campaign_id が不正です', 400); }
    $emails = [];
    foreach (Db::all('SELECT from_address FROM campaigns WHERE id = ?', [$cid]) as $r) {
        $a = strtolower(trim((string) ($r['from_address'] ?? '')));
        if ($a !== '') { $emails[$a] = true; }
    }
    foreach (Db::all('SELECT from_address FROM campaign_contents WHERE campaign_id = ?', [$cid]) as $r) {
        $a = strtolower(trim((string) ($r['from_address'] ?? '')));
        if ($a !== '') { $emails[$a] = true; }
    }
    // 送信元が1つも設定されていない場合は空配列を返す(=どの Maildir にも一致せず0件)。
    return array_keys($emails);
}

/**
 * ?campaign_id 指定時、そのキャンペーンの送信開始 start_at('Y-m-d H:i:s')を返す。
 * 返信の下限(これより前=別キャンペーン/期間外の古いメール)を自動で切るために使う。
 * 同じ送信元アドレスを複数キャンペーンが使い回すため、送信元一致だけでは期間外が混ざる。
 * 返信の上限(いつまでの返信を見るか)は画面の終了日フィルタ(end_date)で手動指定する。
 * start_at 未設定なら null(下限なし)。
 */
function reply_maildir_campaign_start(): ?string
{
    if (!isset($_GET['campaign_id']) || $_GET['campaign_id'] === '') { return null; }
    $cid = (int) $_GET['campaign_id'];
    if ($cid < 1) { json_error('campaign_id が不正です', 400); }
    $row = Db::one('SELECT start_at FROM campaigns WHERE id = ?', [$cid]);
    if ($row === null) { return null; }
    $start = trim((string) ($row['start_at'] ?? ''));
    $ts = $start !== '' ? strtotime($start) : false;
    return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
}

/**
 * Maildir のアドレスが、キャンペーン送信元アドレス群のいずれかに該当するか。
 * まず完全一致(大小無視)、ダメならローカルパート(@より前)一致でフォールバックする。
 * 訓練の from_address のドメインと、返信を受け取る実 Maildir のドメインが異なる
 * 配送があるため(例: 送信元 event-support@mail.cojp.online → 返信は
 * event-support@gwin.gr.cojp.online の Maildir に届く)。
 */
function reply_maildir_email_matches(string $maildirEmail, array $fromEmails): bool
{
    $md = strtolower(trim($maildirEmail));
    if (in_array($md, $fromEmails, true)) { return true; }
    $localOf = static function (string $e): string {
        $at = strpos($e, '@');
        return $at === false ? $e : substr($e, 0, $at);
    };
    $mdLocal = $localOf($md);
    if ($mdLocal === '') { return false; }
    foreach ($fromEmails as $from) {
        if ($localOf($from) === $mdLocal) { return true; }
    }
    return false;
}

function reply_maildir_compute(): array
{
    $maildirs = discover_maildirs();
    $sender = isset($_GET['sender']) ? (string) $_GET['sender'] : '';
    $startTs = (isset($_GET['start_date']) && $_GET['start_date'] !== '') ? strtotime((string) $_GET['start_date']) : null;
    $endTs   = (isset($_GET['end_date'])   && $_GET['end_date']   !== '') ? strtotime((string) $_GET['end_date'])   : null;
    $keyword = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';

    // campaign_id 指定時は、そのキャンペーンの送信元アドレス(=返信の宛先)に届いた
    // Maildir だけに絞る。返信は訓練の from_address 宛に届くため、キャンペーンの
    // from_address(campaigns + campaign_contents 両方)と一致する Maildir アカウントの
    // 受信メールだけを「そのキャンペーンの返信」として扱う(2026-08-23)。
    // 返信者タブは superadmin 限定・全テナント横断なのでテナント検証はしない。
    $campaignFromEmails = reply_maildir_campaign_from_addresses();

    // 返信の下限はキャンペーンの送信開始(start_at)。同じ送信元を複数キャンペーンが
    // 使い回すため、送信元一致だけでは他キャンペーン/期間外の古いメールが混ざる。
    // 送信開始より前のメールは別キャンペーン/期間外として落とす。画面の開始日フィルタが
    // より遅ければそちらを優先(利用者が明示的に絞った範囲を尊重)。上限(いつまでの返信か)は
    // 画面の終了日フィルタ(end_date)で手動指定する。
    $campaignStart = reply_maildir_campaign_start();
    if ($campaignStart !== null) {
        $csTs = strtotime($campaignStart);
        if ($csTs !== false) { $startTs = ($startTs === null) ? $csTs : max($startTs, $csTs); }
    }

    $allMails = [];
    $counts = [];
    foreach ($maildirs as $key => $config) {
        $counts[$key] = 0;
        if ($sender !== '' && $sender !== $key) { continue; }
        // campaign_id 指定時、この Maildir がキャンペーン送信元と一致しなければ除外。
        if ($campaignFromEmails !== null && !reply_maildir_email_matches($config['email'], $campaignFromEmails)) {
            continue;
        }
        $mails = maildir_list($config['maildir']);
        foreach ($mails as &$mail) {
            $mail['sender_account'] = $key;
            $mail['sender_email'] = $config['email'];
        }
        unset($mail);
        if ($startTs !== null || $endTs !== null) {
            $mails = array_values(array_filter($mails, function ($mail) use ($startTs, $endTs) {
                $mt = strtotime((string) $mail['date']);
                if ($mt === false) { return false; }
                if ($startTs !== null && $mt < $startTs) { return false; }
                if ($endTs !== null && $mt > $endTs) { return false; }
                return true;
            }));
        }
        if ($keyword !== '') {
            $mails = array_values(array_filter($mails, function ($mail) use ($keyword) {
                return stripos((string) $mail['from'], $keyword) !== false
                    || stripos((string) $mail['subject'], $keyword) !== false
                    || stripos((string) $mail['from_email'], $keyword) !== false;
            }));
        }
        $counts[$key] = count($mails);
        $allMails = array_merge($allMails, $mails);
    }
    usort($allMails, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

    $accounts = [];
    foreach ($maildirs as $key => $config) { $accounts[$key] = $config['email']; }

    return ['mails' => $allMails, 'counts' => $counts, 'accounts' => $accounts];
}

/** 返信者シートのヘッダ(ログ管理のCSV/XLSX出力と同一)。 */
function reply_maildir_headers(): array
{
    return ['受信日時', '送信元アカウント', '送信元メール', '差出人', '差出人アドレス', '宛先', '件名', '添付'];
}

/**
 * 返信者一覧を XLSX/CSV 用の平坦な配列に整形する。
 * reply_maildir_compute() が返す mails を reply_maildir_headers() の並びに合わせる。
 *
 * @param list<array<string,mixed>> $mails
 * @return list<array<int, string>>
 */
function reply_maildir_table_rows(array $mails): array
{
    $data = [];
    foreach ($mails as $m) {
        $data[] = [
            (string) ($m['date'] ?? ''), (string) ($m['sender_account'] ?? ''), (string) ($m['sender_email'] ?? ''),
            (string) ($m['from'] ?? ''), (string) ($m['from_email'] ?? ''), (string) ($m['to'] ?? ''),
            (string) ($m['subject'] ?? ''), !empty($m['has_attachment']) ? '有' : '',
        ];
    }
    return $data;
}

/**
 * 返信者の中身を出力してよいか(=superadmin か)を返す。
 *
 * 返信者は全テナントのメールが混在する superadmin 限定機能。一方レポートExcelは
 * viewer でも出力できるため、そのまま載せると権限の低い利用者に他テナントの
 * 差出人・件名が渡ってしまう。レポート側はこの判定で中身の有無を切り替える
 * (シート自体は常に作り、権限がなければ理由を1行入れる)。
 */
function reply_maildir_export_allowed(array $user): bool
{
    return ($user['role'] ?? '') === 'superadmin';
}
