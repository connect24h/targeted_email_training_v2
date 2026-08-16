<?php
/**
 * DB のキャンペーン情報から、既存 Python パイプライン（create_beacon_files.py / send_email.py）が
 * 読む CSV 群をテナント別ディレクトリに生成するアダプタ。
 *
 * 既存スクリプトは list.csv(20列) / kenmei.csv / honbun.csv / URL.csv / Attachment.csv を読む。
 * 本アダプタは DB を単一の正とし、それらを一時 CSV として書き出す（既存スクリプトは無改修で流用）。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class PipelineRunner
{
    /**
     * list.csv の列順（既存 send_email.py / create_beacon_files.py が参照する列）。
     * 末尾『メール空欄』は認証画面(master*.html)の email 事前入力を抑制するフラグ。
     * 列は必ず末尾に追加すること。途中挿入は位置依存の既存コードを壊す。
     * pandas(send_email/create_beacon)は列名で読むため末尾追加は後方互換。
     */
    private const LIST_HEADER = [
        '項番', '送信先情報', '件名定型文No', '本文定型文No',
        '本文差し込み1 #$1$#', '本文差し込み2 #$2$#', '本文差し込み3 #$3$#',
        '認証フラグ', '添付ファイル番号', '送信元メールアドレス',
        '苗字', '表示氏名（姓名）', 'メールアドレス（会社）', '会社名', '略称',
        '本務役職名称', '役職カテゴリ', '乱数列', '送信フラグ', '添付ファイル',
        'メール空欄',
    ];

    /**
     * QR型で本文+QR埋め込み文書として生成できる拡張子。
     * create_beacon_files.py の QR_DOCUMENT_FORMATS と一致させること（片方だけ変更しない）。
     */
    private const QR_DOCUMENT_EXTENSIONS = ['docx', 'pdf', 'html', 'xlsx', 'pptx'];

    /**
     * 旧/短縮拡張子を正規の生成フォーマットへ正規化するエイリアス。
     * 実生成は OOXML(.docx/.xlsx/.pptx)のみ可能なため、利用者が慣習的に打つ
     * 'doc'/'xls'/'ppt' は意図どおり対応する OOXML へ寄せる（黙ってQR画像PNGに
     * フォールバックする事故を防ぐ。2026-08 に添付拡張子 'doc' がPNG化した事例）。
     */
    private const QR_EXTENSION_ALIASES = [
        'doc' => 'docx',
        'xls' => 'xlsx',
        'ppt' => 'pptx',
    ];

    /**
     * QR型(link_mode=='qr')の Attachment.csv 拡張子列に書く値を決める。
     *  - $attachmentExt を正規化(doc→docx 等)した上で docx/pdf/html/xlsx/pptx のいずれかなら
     *    'qr_docx'/'qr_pdf'/'qr_html'/'qr_xlsx'/'qr_pptx'
     *    （create_beacon_files.py 側がこの識別子で本文+QR埋め込み文書を生成する）。
     *  - それ以外（未指定/空/不正値）は従来の 'qr'（生QR画像PNG）にフォールバックし後方互換を保つ。
     */
    private static function qrAttachmentExtension(?string $attachmentExt): string
    {
        $ext = strtolower(trim((string) $attachmentExt));
        $ext = self::QR_EXTENSION_ALIASES[$ext] ?? $ext;
        if (in_array($ext, self::QR_DOCUMENT_EXTENSIONS, true)) {
            return 'qr_' . $ext;
        }
        return 'qr';
    }

    /**
     * キャンペーンの CSV 群を data_dir に生成する。生成した data_dir を返す。
     * @throws RuntimeException
     */
    public static function generateCsv(int $campaignId): string
    {
        $c = Db::one('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
        if ($c === null) {
            throw new RuntimeException('キャンペーンが見つかりません');
        }
        $dir = (string) $c['data_dir'];
        if ($dir === '') {
            throw new RuntimeException('data_dir が未設定です');
        }
        self::ensureDir($dir);
        self::ensureDir($dir . '/logs');
        self::ensureDir($dir . '/Attachment');

        // campaign_contents が存在するかをチェック
        $contentCount = Db::one(
            'SELECT COUNT(*) AS cnt FROM campaign_contents WHERE campaign_id = ?',
            [$campaignId]
        );
        $hasContents = (int) ($contentCount['cnt'] ?? 0) > 0;

        if ($hasContents) {
            // 新経路：複数コンテンツ対応
            self::generateCsvMultiContent($campaignId, $c, $dir);
        } else {
            // 旧経路：単一コンテンツ（後方互換）
            self::generateCsvSingleContent($c, $dir);
        }

        return $dir;
    }

    /**
     * 旧経路：campaign の単一テンプレ設定で CSV を生成（後方互換）。
     */
    private static function generateCsvSingleContent(array $c, string $dir): void
    {
        // テンプレート取得
        $subject = self::templateContent($c['subject_template_id']);
        $body    = self::templateContent($c['body_template_id']);
        $phish   = $c['phish_template_id'] !== null
            ? Db::one('SELECT * FROM templates WHERE id = ?', [(int) $c['phish_template_id']])
            : null;
        $authFlag = $phish !== null && $phish['auth_flag'] !== null ? (int) $phish['auth_flag'] : 0;

        // kenmei.csv（件名は1テンプレ = No.1）
        self::writeCsv($dir . '/kenmei.csv', [
            ['項番', '件名'],
            [1, $subject],
        ]);
        // honbun.csv（本文は1テンプレ = No.1）
        self::writeCsv($dir . '/honbun.csv', [
            ['項番', '本文定型文', 'Unnamed: 2', 'Unnamed: 3', 'Unnamed: 4'],
            [1, $body, '', '', ''],
        ]);
        // URL.csv（件名No.1 のリンク先 = beacon_url_base）
        $urlBase = self::beaconUrlBase($c);
        self::writeCsv($dir . '/URL.csv', [
            ['件名定型文No', 'URL'],
            [1, $urlBase],
        ]);
        // Attachment.csv（添付 = No.1）
        // QR型は 'qr'（従来PNG）または 'qr_docx'/'qr_pdf'/'qr_html'（本文+QR埋め込み文書）を書く。
        // それ以外の link_mode(attachment等)は attachment_ext をそのまま使う（従来動作）。
        $linkModeSingle = (string) ($c['link_mode'] ?? 'link');
        $ext = $linkModeSingle === 'qr'
            ? self::qrAttachmentExtension($c['attachment_ext'] ?? null)
            : (string) ($c['attachment_ext'] ?? 'html');
        $zip = (int) ($c['attachment_zip'] ?? 0);
        self::writeCsv($dir . '/Attachment.csv', [
            ['項番', '添付ファイル名', '拡張子', 'zipフラグ'],
            [1, 'kunren', $ext, $zip],
        ]);

        // list.csv（対象者ごとに1行）
        $targets = Db::all(
            'SELECT ct.*, t.email AS to_email, t.name AS to_name, t.company, t.department, t.title, t.position_category
             FROM campaign_targets ct
             JOIN targets t ON t.id = ct.target_id
             WHERE ct.campaign_id = ?
             ORDER BY ct.koban',
            [(int) $c['id']]
        );
        $rows = [self::LIST_HEADER];
        $fromAddr = (string) ($c['from_address'] ?? '');
        $suppressPrefillEmail = (int) ($c['suppress_prefill_email'] ?? 0);
        // link_mode で「リンク型 or 添付型」を排他にする。
        //  - link / form : 本文URL = {base}/link-{tracking_id}.html(クリック追跡)、添付なし
        //  - attachment  : 添付あり、本文URLはトップ(#$1$# には誘い文だけで追跡は添付ビーコン)
        $linkMode = (string) ($c['link_mode'] ?? 'link');
        $base = rtrim($urlBase, '/');
        // テストモードの宛先リダイレクト: あれば実To(送信先情報)を均等分配で差し替える。
        $redirect = self::resolveTestRedirect($c);
        $ri = 0;
        foreach ($targets as $t) {
            $surname = self::surname((string) $t['to_name']);
            $tid = (string) $t['tracking_id'];
            // 実際の送信先。リダイレクトありなら emails[i % N]、無ければ本番の宛先。
            $sendTo = $redirect ? $redirect[$ri % count($redirect)] : (string) $t['to_email'];
            $ri++;
            if ($linkMode === 'attachment') {
                $bodyUrl = $base . '/';         // 添付型: 本文はトップ。追跡は添付内ビーコン(kunren-beacon-{tid}.png)
                $attachNo = 1;                   // 添付を付ける
            } else {
                $bodyUrl = $base . '/link-' . $tid . '.html'; // リンク型/フォーム型: 個別追跡URL
                $attachNo = '';                  // 添付を付けない(排他)
            }
            $rows[] = [
                (int) $t['koban'],                 // 項番
                $sendTo,                            // 送信先情報(実To。テスト時はリダイレクト先)
                1,                                  // 件名定型文No
                1,                                  // 本文定型文No
                $bodyUrl,                           // 本文差し込み1 #$1$#
                $surname,                           // 本文差し込み2 #$2$#（宛名の姓）
                '',                                 // 本文差し込み3 #$3$#
                $authFlag,                          // 認証フラグ
                $attachNo,                          // 添付ファイル番号(link/form型は空=添付なし)
                $fromAddr,                          // 送信元メールアドレス
                $surname,                           // 苗字
                (string) $t['to_name'],             // 表示氏名（姓名）
                (string) $t['to_email'],            // メールアドレス（会社）
                (string) ($t['company'] ?? ''),     // 会社名
                '',                                 // 略称
                (string) ($t['title'] ?? ''),       // 本務役職名称
                (string) ($t['position_category'] ?? ''), // 役職カテゴリ
                (string) $t['tracking_id'],         // 乱数列
                '',                                 // 送信フラグ
                '',                                 // 添付ファイル（生成後に埋まる）
                $suppressPrefillEmail,              // メール空欄（認証画面のemail事前入力抑制）
            ];
        }
        self::writeCsv($dir . '/list.csv', $rows);
    }

    /**
     * 新経路：campaign_contents により、コンテンツ単位で複数行 CSV を生成。
     */
    private static function generateCsvMultiContent(int $campaignId, array $c, string $dir): void
    {
        $urlBase = self::beaconUrlBase($c);
        $base = rtrim($urlBase, '/');

        // campaign_contents をcontent_no順で取得（各content単位でマスタCSV行を生成）
        $contents = Db::all(
            'SELECT * FROM campaign_contents WHERE campaign_id = ? ORDER BY content_no',
            [$campaignId]
        );

        // kenmei.csv: [項番, 件名]
        $kenMeiRows = [['項番', '件名']];
        foreach ($contents as $content) {
            $contentNo = (int) $content['content_no'];
            $subjectContent = self::templateContent($content['subject_template_id']);
            $kenMeiRows[] = [$contentNo, $subjectContent];
        }
        self::writeCsv($dir . '/kenmei.csv', $kenMeiRows);

        // honbun.csv: [項番, 本文定型文, Unnamed: 2, Unnamed: 3, Unnamed: 4]
        $honbunRows = [['項番', '本文定型文', 'Unnamed: 2', 'Unnamed: 3', 'Unnamed: 4']];
        foreach ($contents as $content) {
            $contentNo = (int) $content['content_no'];
            $bodyContent = self::templateContent($content['body_template_id']);
            $honbunRows[] = [$contentNo, $bodyContent, '', '', ''];
        }
        self::writeCsv($dir . '/honbun.csv', $honbunRows);

        // URL.csv: [content_no, URL]。コンテンツ別のビーコンURLを優先し、無ければキャンペーン既定。
        $urlRows = [['件名定型文No', 'URL']];
        foreach ($contents as $content) {
            $contentNo = (int) $content['content_no'];
            // content.beacon_base があればそれ、無ければ campaign($c)の beacon_base、無ければ config.ini。
            $contentUrlBase = self::beaconUrlBase(['beacon_base' => $content['beacon_base'] ?? ($c['beacon_base'] ?? null)]);
            $urlRows[] = [$contentNo, $contentUrlBase];
        }
        self::writeCsv($dir . '/URL.csv', $urlRows);

        // Attachment.csv: [項番, 添付ファイル名, 拡張子, zipフラグ]
        $attachRows = [['項番', '添付ファイル名', '拡張子', 'zipフラグ']];
        foreach ($contents as $content) {
            $contentNo = (int) $content['content_no'];
            // QR型は attachment_ext(docx/pdf/html/xlsx/pptx) に応じて 'qr_docx' 等
            //（本文+QR埋め込み文書。create_beacon_files.py がトリガーとして判定）にするか、
            // 未指定/不正値なら従来の 'qr'（生QR画像PNG・後方互換）にフォールバックする。
            // それ以外の添付型は attachment_ext を使う。
            $linkMode = (string) ($content['link_mode'] ?? 'link');
            $ext = $linkMode === 'qr'
                ? self::qrAttachmentExtension($content['attachment_ext'] ?? null)
                : (string) ($content['attachment_ext'] ?? 'html');
            $zip = (int) ($content['attachment_zip'] ?? 0);
            $attachRows[] = [$contentNo, 'kunren', $ext, $zip];
        }
        self::writeCsv($dir . '/Attachment.csv', $attachRows);

        // list.csv: 対象者ごとに、割り当てられた content_no に基づいて行を生成
        $targets = Db::all(
            'SELECT ct.*, t.email AS to_email, t.name AS to_name, t.company, t.department, t.title, t.position_category
             FROM campaign_targets ct
             JOIN targets t ON t.id = ct.target_id
             WHERE ct.campaign_id = ?
             ORDER BY ct.koban',
            [$campaignId]
        );

        // campaign_contentsを連想配列でキャッシュ（content_noをキー）
        $contentMap = [];
        foreach ($contents as $content) {
            $contentMap[(int) $content['content_no']] = $content;
        }

        $rows = [self::LIST_HEADER];
        $fromAddr = (string) ($c['from_address'] ?? '');
        // テストモードの宛先リダイレクト: あれば実To(送信先情報)を均等分配で差し替える。
        $redirect = self::resolveTestRedirect($c);
        $ri = 0;

        foreach ($targets as $t) {
            $targetContentNo = (int) ($t['content_no'] ?? 1); // デフォルトは1
            $targetContent = $contentMap[$targetContentNo] ?? null;

            if ($targetContent === null) {
                // フォールバック: content_no が無い、または無効なら content_no=1 を用いる
                $targetContent = $contentMap[1] ?? null;
                if ($targetContent === null) {
                    continue; // コンテンツ不在なら skip
                }
                $targetContentNo = 1;
            }

            $authFlag = 0;
            if ($targetContent['phish_template_id'] !== null) {
                $phish = Db::one('SELECT auth_flag FROM templates WHERE id = ?', [(int) $targetContent['phish_template_id']]);
                if ($phish !== null && $phish['auth_flag'] !== null) {
                    $authFlag = (int) $phish['auth_flag'];
                }
            }

            // link_mode で分岐（attachment型か否か）
            $linkMode = (string) ($targetContent['link_mode'] ?? 'link');
            $surname = self::surname((string) $t['to_name']);
            $tid = (string) $t['tracking_id'];

            // コンテンツ別のビーコンURL/送信元(無ければキャンペーン既定にフォールバック)。
            $contentBase = rtrim(self::beaconUrlBase(['beacon_base' => $targetContent['beacon_base'] ?? ($c['beacon_base'] ?? null)]), '/');
            $contentFromAddr = (string) ($targetContent['from_address'] ?? $fromAddr);

            if ($linkMode === 'attachment' || $linkMode === 'qr') {
                // 添付型/QR型: 本文はトップ、添付を付ける(添付番号=content_no)。
                //  - attachment: 添付HTML(偽ログイン)。追跡は添付内ビーコン。
                //  - qr: 添付はQR画像PNG。QRの中身が link-{tid}.html なので、スキャン→追跡URL→click記録。
                $bodyUrl = $contentBase . '/';
                $attachNo = $targetContentNo;
            } else {
                $bodyUrl = $contentBase . '/link-' . $tid . '.html'; // リンク型/フォーム型: 個別追跡URL
                $attachNo = '';                  // 添付を付けない
            }
            // 本文URL抑制フラグが立っていれば #$1$# を空にする(添付/QR型で本文に半端なURLを残さない)。
            // 追跡は添付内ビーコン/QRで行うので本文URLは不要。
            if (!empty($targetContent['suppress_body_url'])) {
                $bodyUrl = '';
            }

            // 実際の送信先。リダイレクトありなら emails[i % N]、無ければ本番の宛先。
            $sendTo = $redirect ? $redirect[$ri % count($redirect)] : (string) $t['to_email'];
            $ri++;

            $rows[] = [
                (int) $t['koban'],                 // 項番
                $sendTo,                            // 送信先情報(実To。テスト時はリダイレクト先)
                $targetContentNo,                  // 件名定型文No = content_no
                $targetContentNo,                  // 本文定型文No = content_no
                $bodyUrl,                           // 本文差し込み1 #$1$#
                $surname,                           // 本文差し込み2 #$2$#（宛名の姓）
                '',                                 // 本文差し込み3 #$3$#
                $authFlag,                          // 認証フラグ
                $attachNo,                          // 添付ファイル番号(link/form型は空=添付なし、attachment型はcontent_no)
                $contentFromAddr,                   // 送信元メールアドレス(コンテンツ別)
                $surname,                           // 苗字
                (string) $t['to_name'],             // 表示氏名（姓名）
                (string) $t['to_email'],            // メールアドレス（会社）
                (string) ($t['company'] ?? ''),     // 会社名
                '',                                 // 略称
                (string) ($t['title'] ?? ''),       // 本務役職名称
                (string) ($t['position_category'] ?? ''), // 役職カテゴリ
                (string) $t['tracking_id'],         // 乱数列
                '',                                 // 送信フラグ
                '',                                 // 添付ファイル（生成後に埋まる）
                (int) ($targetContent['suppress_prefill_email'] ?? 0), // メール空欄（認証画面のemail事前入力抑制）
            ];
        }
        self::writeCsv($dir . '/list.csv', $rows);
    }

    private static function templateContent($id): string
    {
        if ($id === null) {
            return '';
        }
        $t = Db::one('SELECT content FROM templates WHERE id = ?', [(int) $id]);
        return $t !== null ? (string) $t['content'] : '';
    }

    private static function surname(string $fullname): string
    {
        // 「田中 太郎」「田中　太郎」→「田中」。空白が無ければ全体を返す。
        $parts = preg_split('/[\s　]+/u', trim($fullname), 2);
        return $parts !== false && $parts[0] !== '' ? $parts[0] : $fullname;
    }


    /**
     * ビーコン/リンクページのベース URL を返す（末尾スラッシュ付き）。
     * キャンペーンに beacon_base 指定があればそれを優先し、無ければ config.ini
     * の beacon_url_base、それも無ければ既定 IP にフォールバックする（後方互換）。
     * ログ管理のファイル一覧(api/logs.php)からも同じ解決を使うため public。
     */
    public static function beaconUrlBase(?array $c = null): string
    {
        // キャンペーン別 base URL（P6）。指定があれば最優先。
        if ($c !== null && !empty($c['beacon_base'])) {
            return rtrim((string) $c['beacon_base'], '/') . '/';
        }
        // 既存 config.ini の beacon_url_base を踏襲。
        $ini = '/opt/training/bin/config.ini';
        if (is_readable($ini)) {
            $conf = parse_ini_file($ini);
            if ($conf !== false && !empty($conf['beacon_url_base'])) {
                return rtrim((string) $conf['beacon_url_base'], '/') . '/';
            }
        }
        return 'http://85.131.251.224/';
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 02775, true) && !is_dir($dir)) {
            throw new RuntimeException("ディレクトリ作成に失敗: {$dir}");
        }
        // mkdirのmodeはプロセスumaskで削られるため、作成済みの場合も毎回正規化する。
        // setgidにより配下をwww-data groupへ継承し、Webと送信workerの双方が書き込める。
        if (!chmod($dir, 02775)) {
            throw new RuntimeException("ディレクトリ権限の設定に失敗: {$dir}");
        }
    }

    /**
     * テストモードの宛先リダイレクト先を返す。
     * is_test=1 かつ test_redirect_emails が設定されていれば、その email 配列を返す。
     * 本番宛先への誤送信を防ぐため、test modeで有効な宛先がなければ失敗させる。
     */
    private static function resolveTestRedirect(array $c): array
    {
        if ((int) ($c['is_test'] ?? 0) !== 1) {
            return [];
        }
        $raw = trim((string) ($c['test_redirect_emails'] ?? ''));
        if ($raw === '') {
            throw new RuntimeException('テスト送信先が未設定です');
        }
        $emails = [];
        foreach (preg_split('/[,\s]+/', $raw) as $e) {
            $e = trim($e);
            if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $e;
            }
        }
        $emails = array_values(array_unique($emails));
        if ($emails === []) {
            throw new RuntimeException('有効なテスト送信先がありません');
        }
        return $emails;
    }


    private static function writeCsv(string $path, array $rows): void
    {
        $fp = fopen($path, 'w');
        if ($fp === false) {
            throw new RuntimeException("CSV 書き込みに失敗: {$path}");
        }
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
        // group 書き込み可(0664)にする。生成確認は www-data、送信ワーカーは training が
        // 同じ data_dir に書くため、どちらが先に作っても相手が上書きできるようにする。
        @chmod($path, 0664);
    }
}
