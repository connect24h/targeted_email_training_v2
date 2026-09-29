<?php
declare(strict_types=1);

/**
 * 不審メールの一覧の CSV 出力(A-8、G62)のテスト。
 * - UTF-8 の BOM、行末 CRLF、引用符の二重化、先頭の = + - @ の無害化(tet2_csv_sanitize)
 * - 読める人(operator 以上)とテナントの範囲、絞り込みは一覧と同じ
 * - システム管理者がテナントを選ばない時は全テナント(テナントIDの列つき)
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/SuspiciousMailStore.php';
require_once __DIR__ . '/../lib/VirusTotalClient.php';
require_once __DIR__ . '/../lib/Secrets.php';
load_api('suspicious_mails');

// 合成の行(.eml は使わない。CSV は保存済みの列だけを出す)
$mail = static function (?int $tenant, string $subject, string $from, string $category, string $status, string $at): void {
    Db::run("INSERT INTO suspicious_mails (tenant_id, source, reporter_email, raw_path, sha256, raw_bytes, subject, from_email, from_name,
             received_at, analysis_json, findings_json, score, suggested_category, category, status, priority, assigned_to, note)
             VALUES (?, 'maildir', 'reporter@example.test', '/nonexistent.eml', ?, 10, ?, ?, '差出人', ?, '{}', '[]', 30, 'threat', ?, ?, 'high',
                     '=HYPERLINK(\"x\")', '社内のメモ(出さない)')",
        [$tenant, hash('sha256', $subject . $tenant), $subject, $from, $at, $category, $status]);
};
$mail(1, '=cmd|\' /C calc\'!A0', '+evil@bad.test', 'threat', 'open', '2026-09-20 10:00:00');
$mail(1, 'お支払い "至急", 確認', '-x@bad.test', 'undetermined', 'resolved', '2026-09-21 10:00:00');
$mail(1, '@SUM(1+1)', 'ok@bad.test', 'spam', 'open', '2026-09-22 10:00:00');
$mail(2, '他社の不審メール', 'other@bad.test', 'threat', 'open', '2026-09-23 10:00:00');
$mail(null, 'テナント未確定', 'unknown@bad.test', 'threat', 'open', '2026-09-24 10:00:00');

// load_api は bootstrap.php を読まないので、本物のサニタイザだけを自リポジトリの bootstrap.php から取り出す(既存の CSV のテストと同じ)
$bootstrap = (string) file_get_contents(__DIR__ . '/../lib/bootstrap.php');
preg_match('/function tet2_csv_sanitize\(.*?\n\}/s', $bootstrap, $match);
eval($match[0]);

// exit する出力の経路は別のプロセスで実行する: php このファイル --csv <role> [query]
if (($argv[1] ?? '') === '--csv') {
    $GLOBALS['__TET2_TEST_ROLE'] = $argv[2];
    parse_str($argv[3] ?? '', $query);
    $_GET = $query;
    try {
        sm_handle_export_csv(require_role('operator'));
    } catch (Tet2TestExit $e) {
        echo 'EXIT ' . $e->httpCode;
        exit(0);
    }
}
$runCsv = static function (string $role, string $query = ''): string {
    $proc = proc_open([PHP_BINARY, __FILE__, '--csv', $role, $query], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    if ($err !== '' && !str_contains($err, 'headers already sent')) {
        throw new RuntimeException('CSV の子プロセスが失敗: ' . $err);
    }
    return $out;
};
$parse = static fn(string $csv): array => array_map(
    static fn(string $line): array => str_getcsv($line, ',', '"', ''),
    explode("\r\n", rtrim(substr($csv, 3), "\r\n"))
);

// 形式(純関数)
$csv = sm_csv([['tenant_id' => 1, 'source' => 'upload', 'received_at' => '2026-09-01 00:00:00', 'from_name' => '@名前', 'from_email' => 'a@b.test',
    'subject' => "複数\n行と \"引用\"", 'reporter_email' => null, 'suggested_category' => 'safe', 'category' => 'training', 'status' => 'in_progress',
    'priority' => 'low', 'assigned_to' => null, 'score' => 0, 'is_training' => 1, 'created_at' => '2026-09-01 00:00:01']], false);
check(str_starts_with($csv, "\xEF\xBB\xBF受付日時,受付元,送信者名,送信者,件名,報告者,推奨分類,分類,確認状況,優先度,担当,スコア,訓練メール,登録日時\r\n"),
    '形式: BOM と見出しで始まり、行末は CRLF');
check(str_contains($csv, ",'@名前,") && str_contains($csv, "\"複数\n行と \"\"引用\"\"\""), '形式: 先頭の @ を無害化し、改行と引用符を含む値を引用する');
check(str_contains($csv, ',アップロード,') && str_contains($csv, ',訓練メール,確認中,低,') && str_contains($csv, ',はい,'), '形式: 分類や状況は日本語の名前で出す');

// operator: 自テナントの3件だけ。式の無害化とメモを出さないこと
$out = $runCsv('operator');
check(str_starts_with($out, "\xEF\xBB\xBF受付日時,"), 'operator: CSV を出力できる(BOM つき)');
$rows = $parse($out);
check(count($rows) === 4, 'operator: 見出しと自テナントの3件だけ(ほかのテナントと未確定は出ない)');
check(!str_contains($out, '他社の不審メール') && !str_contains($out, 'テナント未確定'), 'テナントの分離: ほかのテナントの行を出さない');
check($rows[1][4] === "'@SUM(1+1)" && $rows[3][4] === "'=cmd|' /C calc'!A0", '式の無害化: 件名の先頭の = と @ に \' を付ける');
check($rows[3][3] === "'+evil@bad.test" && $rows[2][3] === "'-x@bad.test", '式の無害化: 送信者の先頭の + と - に \' を付ける');
check($rows[1][10] === "'=HYPERLINK(\"x\")", '式の無害化: 担当の値も無害化する');
check($rows[2][4] === 'お支払い "至急", 確認', '引用: カンマと引用符を含む件名をそのまま読み戻せる');
check(!str_contains($out, '社内のメモ'), 'メモ(自由記述)は出さない');
check(!str_contains(str_replace("\r\n", '', $out), "\n"), '行末はすべて CRLF');

// 絞り込みは一覧と同じ
$rows = $parse($runCsv('operator', 'status=resolved'));
check(count($rows) === 2 && $rows[1][8] === '対応済', '絞り込み: 状況で絞れる');
$rows = $parse($runCsv('operator', 'q=' . rawurlencode('SUM')));
check(count($rows) === 2 && $rows[1][4] === "'@SUM(1+1)", '絞り込み: キーワードで絞れる');
check($runCsv('operator', 'category=bogus') === 'EXIT 400', '絞り込み: 不正な値は 400');

// 権限: 閲覧者は 403、ほかのテナントの指定は 403
check($runCsv('viewer') === 'EXIT 403', '権限: 閲覧者は 403(一覧と同じく operator 以上)');
check($runCsv('tenant_admin', 'tenant_id=2') === 'EXIT 403', '権限: ほかのテナントを指定すると 403');

// システム管理者: テナントを選ばなければ全テナント(テナントIDの列つき)、選べばそのテナントだけ
$all = $parse($runCsv('superadmin'));
check($all[0][0] === 'テナントID' && count($all) === 6 && in_array('未確定', array_column($all, 0), true), 'システム管理者: 全テナントとテナントIDの列');
$two = $parse($runCsv('superadmin', 'tenant_id=2'));
check(count($two) === 2 && $two[0][0] === '受付日時' && $two[1][4] === '他社の不審メール', 'システム管理者: テナントを選ぶとそのテナントだけ');

echo "ALL TESTS PASSED\n";
