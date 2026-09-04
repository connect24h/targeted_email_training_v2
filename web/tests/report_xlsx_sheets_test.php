<?php
declare(strict_types=1);

/**
 * レポート Excel のシート構成テスト(2026-08-30 追加)。
 *
 * ログ管理の「訓練結果」「訓練結果ログ(明細)」と同じ内容を、レポートExcelにも
 * シートとして載せた。両者が別実装に分岐して片方だけ直る事故を防ぐため、
 *   1) 行生成が lib/TrainingLogRows.php の共有関数を通ること
 *   2) api/report.php が7シートを正しい名前・順序で addSheet すること
 *   3) SimpleXlsx が日本語や括弧を含むシート名を壊さないこと
 * を固定する。
 */

require_once __DIR__ . '/helpers.php';
tet2_test_boot();
require_once __DIR__ . '/../lib/SimpleXlsx.php';
// キャッシュ書き込みが正本の data/ を汚さないよう一時ファイルへ向ける。
$ipCacheTmp = tempnam(sys_get_temp_dir(), 'ipxlsxtest_') ?: (sys_get_temp_dir() . '/ipxlsxtest.json');
@unlink($ipCacheTmp);
putenv('TET2_IP_CACHE=' . $ipCacheTmp);
require_once __DIR__ . '/../lib/TrainingLogRows.php';
require_once __DIR__ . '/../lib/ReplyMaildir.php';
register_shutdown_function(static function () use ($ipCacheTmp) { @unlink($ipCacheTmp); });

echo "=== レポートExcel: シート構成 ===\n";

// ---- 1) 共有関数が両APIから見えること ----
check(function_exists('training_results_rows'), '共有: training_results_rows が定義されている');
check(function_exists('training_results_headers'), '共有: training_results_headers が定義されている');
check(function_exists('training_log_detail_rows'), '共有: training_log_detail_rows が定義されている');
check(function_exists('training_log_detail_headers'), '共有: training_log_detail_headers が定義されている');
check(function_exists('training_log_detail_table_rows'), '共有: training_log_detail_table_rows が定義されている');
check(function_exists('logs_campaign_filter'), '共有: logs_campaign_filter が定義されている');

// ---- 2) ヘッダがログ管理のXLSX出力と一致すること ----
$trHead = training_results_headers();
check(count($trHead) === 11, '訓練結果ヘッダ: 11列');
check($trHead[0] === 'キャンペーン' && $trHead[10] === '認証', '訓練結果ヘッダ: 先頭=キャンペーン / 末尾=認証');
$tldHead = training_log_detail_headers();
check(count($tldHead) === 21, '明細ヘッダ: 21列');
check($tldHead[0] === '日時' && $tldHead[20] === 'UserAgent', '明細ヘッダ: 先頭=日時 / 末尾=UserAgent');
check(in_array('国', $tldHead, true), '明細ヘッダ: 国の列がある');

// ---- 3) 整形関数がヘッダと同じ列数の行を返すこと(ズレ検知) ----
$sample = [[
    'timestamp' => '2026-08-30 10:00:00', 'random' => '1234567890', 'duplicate' => true,
    'duplicate_count' => 3, 'type' => 'link_click', 'recipient_email' => 'a@example.test',
    'fullname' => '試験 太郎', 'company_email' => 'a@example.test', 'company' => 'テスト社',
    'abbreviation' => '', 'position' => '課長', 'position_category' => '管理職',
    'email' => 'in@example.test', 'password' => 'pw', 'ip' => '198.51.100.9',
    'country' => 'Japan (JP)', 'location' => 'Tokyo', 'isp' => 'ISP', 'org' => 'Org',
    'as' => 'AS64500', 'hostname' => 'h.example.test', 'useragent' => 'UA/1.0',
]];
$tldTable = training_log_detail_table_rows($sample);
check(count($tldTable) === 1, '明細整形: 1行入れたら1行返る');
check(count($tldTable[0]) === count($tldHead), '明細整形: 列数がヘッダと一致(21列)');
check($tldTable[0][2] === '3回', '明細整形: 重複列は「n回」表記');
check($tldTable[0][14] === 'Japan (JP)', '明細整形: 国が15列目に入る');

// ---- 4) api/report.php が7シートを正しい順序で addSheet していること ----
// export_xlsx は download() で exit するため関数は呼べない。ソースの addSheet 呼び出し順を固定する。
$reportSrc = file_get_contents(__DIR__ . '/../api/report.php');
check($reportSrc !== false, 'api/report.php を読める');
preg_match_all("/\\\$xlsx->addSheet\('([^']+)'/", (string) $reportSrc, $m);
$sheetNames = $m[1] ?? [];
$expected = ['サマリー', '会社別', '役職別', 'コンテンツ別', '日別タイムライン', '訓練結果', '訓練結果ログ(明細)', '返信者'];
check($sheetNames === $expected,
    'レポートExcel: 8シートが期待順で並ぶ (実際: ' . implode(' / ', $sheetNames) . ')');
check(strpos((string) $reportSrc, "require_once __DIR__.\"/../lib/TrainingLogRows.php\"") !== false,
    'api/report.php が共有ライブラリを require している');
check(strpos((string) $reportSrc, 'training_results_rows($tenantId)') !== false,
    'レポートExcel: 訓練結果は共有関数で行生成する');
check(strpos((string) $reportSrc, 'training_log_detail_rows($tenantId)') !== false,
    'レポートExcel: 明細は共有関数で行生成する');

// 認証/表示の既存列を維持したまま、認証/対象数の列を4シートへ追加する。
preg_match_all('/認証率 \(認証\/対象数 %\)/u', (string) $reportSrc, $authTargetRateMatches);
check(count($authTargetRateMatches[0] ?? []) === 4,
    'レポートExcel: 認証/対象数の率がサマリーと3明細ヘッダにある');

// レポートUIも一覧・会社別・役職別・コンテンツ別の4表で対象比を表示する。
$indexSrc = (string) file_get_contents(__DIR__ . '/../index.html');
$appSrc = (string) file_get_contents(__DIR__ . '/../app.js');
check(str_contains($indexSrc, '認証率(対象比)')
    && substr_count($indexSrc, '認証率(対象比)') === 4,
    'レポートUI: 認証率(対象比)ヘッダが4表にある');
check(str_contains($appSrc, 'function authTargetRateOf'),
    'レポートUI: authTargetRateOf ヘルパーがある');
check(str_contains($appSrc, "query.set('start_date', sd)"),
    'レポートUI: Excel出力URLにstart_dateを付与する');
check(!str_contains($appSrc, 'el.disabled = committed'),
    'レポートUI: 確定済みでも期間コントロールを無効化しない');
check(str_contains($indexSrc, 'title="期間指定中は確定値ではなくリアルタイム集計を表示します"'),
    'レポートUI: 期間指定中の集計仕様をtitleで案内する');

// ---- 5) SimpleXlsx が日本語・括弧入りのシート名を壊さないこと ----
$xlsx = new SimpleXlsx();
$xlsx->addSheet('訓練結果', [training_results_headers()]);
$xlsx->addSheet('訓練結果ログ(明細)', [$tldHead]);
$tmpXlsx = tempnam(sys_get_temp_dir(), 'sheetname_') ?: (sys_get_temp_dir() . '/sheetname.xlsx');
$xlsx->saveToFile($tmpXlsx);
check(filesize($tmpXlsx) > 0, 'SimpleXlsx: ファイルが生成される');
$zip = new ZipArchive();
check($zip->open($tmpXlsx) === true, 'SimpleXlsx: 生成物が壊れていないZIPである');
$wb = (string) $zip->getFromName('xl/workbook.xml');
$zip->close();
@unlink($tmpXlsx);
check(strpos($wb, 'name="訓練結果"') !== false, 'シート名: 日本語がそのまま入る');
check(strpos($wb, 'name="訓練結果ログ(明細)"') !== false, 'シート名: 括弧入りが壊れない');

// ---- 6) ヘッダ行の配色(青背景に白文字) ----
// 背景だけ青くして文字色を指定しないと、既定の黒文字が濃紺に重なって読めなかった
// (2026-08-30 修正)。ヘッダ用フォントに白が入っていることを固定する。
$xlsx2 = new SimpleXlsx();
$xlsx2->addSheet('配色確認', [['見出しA', '見出しB'], ['値1', '値2']]);
$tmpStyle = tempnam(sys_get_temp_dir(), 'xlsxstyle_') ?: (sys_get_temp_dir() . '/xlsxstyle.xlsx');
$xlsx2->saveToFile($tmpStyle);
$zip2 = new ZipArchive();
check($zip2->open($tmpStyle) === true, '配色: 生成物を開ける');
$stylesXml = (string) $zip2->getFromName('xl/styles.xml');
$sheetXml = (string) $zip2->getFromName('xl/worksheets/sheet1.xml');
$zip2->close();
@unlink($tmpStyle);

// ヘッダ用フォント(fontId=1)は太字かつ白。
check(strpos($stylesXml, '<font><b/><color rgb="FFFFFFFF"/>') !== false,
    '配色: ヘッダフォントが太字+白文字(FFFFFFFF)');
// 背景の青は従来どおり維持。
check(strpos($stylesXml, '<fgColor rgb="FF4472C4"/>') !== false,
    '配色: ヘッダ背景の青(FF4472C4)を維持');
// ヘッダ書式 s=1 が「白文字フォント」と「青背景」を組で参照する。
check(strpos($stylesXml, '<xf numFmtId="0" fontId="1" fillId="2"') !== false,
    '配色: ヘッダ書式が fontId=1(白) と fillId=2(青) を参照');
// 本文フォント(fontId=0)には色を付けない(データ行は黒のまま)。
check(strpos($stylesXml, '<font><sz val="11"/><name val="Calibri"/></font>') !== false,
    '配色: 本文フォントは色指定なし(黒のまま)');
// 1行目だけが s=1、2行目(データ)は s=1 を使わない。
$rowsXml = [];
if (preg_match_all('/<row [^>]*>(.*?)<\/row>/s', $sheetXml, $rm)) { $rowsXml = $rm[1]; }
check(count($rowsXml) >= 2, '配色: 2行以上が書き出されている');
check(strpos($rowsXml[0] ?? '', 's="1"') !== false, '配色: 1行目(見出し)がヘッダ書式 s=1');
check(strpos($rowsXml[1] ?? '', 's="1"') === false, '配色: 2行目(データ)はヘッダ書式を使わない');

// ---- 7) 返信者シート(2026-08-31 追加) ----
// 返信者は Maildir を直接読む superadmin 限定機能で、全テナントのメールが混在する。
// レポートExcelは viewer でも出力できるため、そのまま載せると権限の低い利用者に
// 他テナントの差出人・件名が渡る。権限で中身を出し分けることを固定する。
check(function_exists('reply_maildir_compute'), '共有: reply_maildir_compute が定義されている');
check(function_exists('reply_maildir_headers'), '共有: reply_maildir_headers が定義されている');
check(function_exists('reply_maildir_table_rows'), '共有: reply_maildir_table_rows が定義されている');
check(function_exists('reply_maildir_export_allowed'), '共有: reply_maildir_export_allowed が定義されている');

$replyHead = reply_maildir_headers();
check(count($replyHead) === 8, '返信者ヘッダ: 8列');
check($replyHead[0] === '受信日時' && $replyHead[7] === '添付', '返信者ヘッダ: 先頭=受信日時 / 末尾=添付');

$replySample = [[
    'date' => '2026-08-31 09:00:00', 'sender_account' => 'kanri', 'sender_email' => 'k@example.test',
    'from' => '試験 花子 <h@example.test>', 'from_email' => 'h@example.test',
    'to' => 'k@example.test', 'subject' => 'Re: 訓練', 'has_attachment' => true,
]];
$replyTable = reply_maildir_table_rows($replySample);
check(count($replyTable) === 1, '返信者整形: 1件入れたら1行返る');
check(count($replyTable[0]) === count($replyHead), '返信者整形: 列数がヘッダと一致(8列)');
check($replyTable[0][7] === '有', '返信者整形: 添付ありは「有」');
$replyNoAtt = reply_maildir_table_rows([['date' => 'x', 'has_attachment' => false]]);
check($replyNoAtt[0][7] === '', '返信者整形: 添付なしは空');

// 権限判定: superadmin だけが中身を出せる。
check(reply_maildir_export_allowed(['role' => 'superadmin']) === true,
    '返信者権限: superadmin は出力できる');
foreach (['viewer', 'operator', 'tenant_admin'] as $role) {
    check(reply_maildir_export_allowed(['role' => $role]) === false,
        "返信者権限: {$role} は出力できない(全テナント混在のため)");
}
check(reply_maildir_export_allowed([]) === false, '返信者権限: role 不明は出力できない');

// api/report.php が権限判定を通してからシートを組むこと。
check(strpos((string) $reportSrc, 'reply_maildir_export_allowed($user)') !== false,
    'レポートExcel: 返信者は権限判定を通す');
check(strpos((string) $reportSrc, "require_once __DIR__.\"/../lib/ReplyMaildir.php\"") !== false,
    'api/report.php が返信者ライブラリを require している');
// 権限がなくてもシート自体は作る(構成を権限で変えない)。
check(preg_match("/else \\{\\s*\\\$replyRows = \\[reply_maildir_headers\\(\\)/", (string) $reportSrc) === 1,
    'レポートExcel: 権限がなくても返信者シートは作る(ヘッダ+理由)');

echo "ALL TESTS PASSED\n";
