<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
$path = tet2_test_boot();
load_api('edu_deliveries');
// DELETE直前に別接続で受講開始をcommitし、競合順序を決定的に再現する。
$source = file_get_contents(__DIR__ . '/../api/edu_deliveries.php');
$start = strpos($source, 'function edu_d_handle_delete(');
$end = strpos($source, "\ntry {", $start);
$handler = substr($source, $start, $end - $start);
$handler = str_replace('function edu_d_handle_delete(', 'function test_racing_delete(', $handler);
$handler = str_replace('Db::run(', 'test_delete_write(', $handler);
eval($handler);
$GLOBALS['race_assignment'] = null;
$GLOBALS['race_db'] = $path;
function test_delete_write(string $sql, array $params): int {
    if (str_starts_with(ltrim($sql), 'DELETE') && $GLOBALS['race_assignment'] !== null) {
        $other = new PDO('sqlite:' . $GLOBALS['race_db']);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $other->prepare("UPDATE edu_assignments SET status='started' WHERE id=?");
        $stmt->execute([$GLOBALS['race_assignment']]);
        $GLOBALS['race_assignment'] = null;
    }
    return Db::run($sql, $params);
}
$tenantId = (int) current_user()['tenant_id'];
$id = Db::insert("INSERT INTO edu_deliveries (tenant_id,title,status) VALUES (?, '競合配信', 'running')", [$tenantId]);
$targetId = Db::insert("INSERT INTO targets (tenant_id,email) VALUES (?, 'race@example.test')", [$tenantId]);
$assignmentId = Db::insert("INSERT INTO edu_assignments (tenant_id,delivery_id,target_id,access_token,status) VALUES (?,?,?,'12345678901234567890123456789012','assigned')", [$tenantId,$id,$targetId]);
$GLOBALS['race_assignment'] = $assignmentId;
$r = call_handler('test_racing_delete', ['id' => $id]);
check($r['code'] === 409, 'DELETE直前の受講開始で削除拒否');
check(Db::one('SELECT status FROM edu_assignments WHERE id=?', [$assignmentId])['status'] === 'started', '競合後の開始履歴を保持');
check(Db::one('SELECT id FROM edu_deliveries WHERE id=?', [$id]) !== null, '配信を保持');
Db::run("UPDATE edu_assignments SET status='completed' WHERE id=?", [$assignmentId]);
$r = call_handler('edu_d_handle_delete', ['id' => $id]);
check($r['code'] === 409, '完了履歴のある配信も拒否');
Db::run("UPDATE edu_assignments SET status='assigned' WHERE id=?", [$assignmentId]);
$r = call_handler('edu_d_handle_delete', ['id' => $id]);
check($r['code'] === 200, '未受講配信は削除可能');
check(Db::one('SELECT id FROM edu_assignments WHERE id=?', [$assignmentId]) === null, '未受講割当だけ連鎖削除');
$r = call_handler('edu_d_handle_delete', ['id' => $id]);
check($r['code'] === 404, '削除済み配信は404');
// 逆順: token解決後、受講開始UPDATE直前に配信削除が先にcommitする。
$source = file_get_contents(__DIR__ . '/../api/edu_take.php');
$start = strpos($source, 'function take_error(');
$source = substr($source, $start, strpos($source, "\ntry {", $start) - $start);
$source = str_replace('take_json(', 'json_out(', $source);
$source = str_replace('Db::run(', 'test_start_write(', $source);
eval($source);
$GLOBALS['race_delivery'] = null;
function test_start_write(string $sql, array $params): int {
    if (str_contains($sql, "SET status='started', started_at=") && $GLOBALS['race_delivery'] !== null) {
        $other = new PDO('sqlite:' . $GLOBALS['race_db']);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->exec('PRAGMA foreign_keys=ON');
        $stmt = $other->prepare('DELETE FROM edu_deliveries WHERE id=?');
        $stmt->execute([$GLOBALS['race_delivery']]);
        $GLOBALS['race_delivery'] = null;
    }
    return Db::run($sql, $params);
}
$id = Db::insert("INSERT INTO edu_deliveries (tenant_id,title,status) VALUES (?, '削除先行', 'running')", [$tenantId]);
$categoryId = Db::insert("INSERT INTO edu_categories (tenant_id,name,slug) VALUES (?,'競合テスト','race-test')", [$tenantId]);
$questionId = Db::insert("INSERT INTO edu_questions (tenant_id,category_id,title,options,correct_answer) VALUES (?,?,'競合設問','[\"A\",\"B\"]','[0]')", [$tenantId,$categoryId]);
Db::run('INSERT INTO edu_delivery_questions (delivery_id,question_id) VALUES (?,?)', [$id,$questionId]);
Db::run("INSERT INTO edu_assignments (tenant_id,delivery_id,target_id,access_token,status) VALUES (?,?,?,'22345678901234567890123456789012','assigned')", [$tenantId,$id,$targetId]);
$_GET['token'] = '22345678901234567890123456789012';
$GLOBALS['race_delivery'] = $id;
$r = call_handler('take_handle_start');
check($r['code'] === 404, '削除が先行した受講開始は成功を返さず404');
check(Db::one('SELECT id FROM edu_deliveries WHERE id=?', [$id]) === null, '削除先行で履歴を再生成しない');
echo "RACE TESTS PASSED\n";
