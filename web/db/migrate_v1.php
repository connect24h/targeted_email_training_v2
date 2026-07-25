<?php declare(strict_types=1);
/**
 * TET v1(/tet) → v2 データ移行スクリプト（CLI専用）。
 *
 * v1 の list.csv / campaigns.json / training_history.json を読み取り専用で参照し、
 * v2 SQLite の targets / campaigns へ、指定テナント配下に隔離して投入する。
 *
 * 安全設計:
 *   - デフォルトは DRY-RUN（DB を一切変更しない）。実書込は --commit 指定時のみ。
 *   - v1 の稼働実体は読み取りのみ（書込・削除しない）。
 *   - 移行先テナントは既存必須（自動作成しない＝誤テナント汚染防止）。
 *   - email 重複は skip（既存優先）。UNIQUE(tenant_id,email) に委ねず事前チェックも行う。
 *
 * 使い方:
 *   php migrate_v1.php --tenant=<slug>              # dry-run（既定）
 *   php migrate_v1.php --tenant=<slug> --commit     # 実行
 */

require_once __DIR__ . '/../lib/Db.php';

const V1_LIST_CSV = '/opt/training/bin/data/list.csv';
const V1_CAMPAIGNS = '/opt/training/bin/data/campaigns/campaigns.json';

function mig_args(): array
{
    $args = ['tenant' => null, 'commit' => false];
    foreach ($_SERVER['argv'] ?? [] as $a) {
        if (str_starts_with($a, '--tenant=')) {
            $args['tenant'] = substr($a, 9);
        } elseif ($a === '--commit') {
            $args['commit'] = true;
        }
    }
    return $args;
}

function mig_die(string $msg): never
{
    fwrite(STDERR, "エラー: $msg\n");
    exit(1);
}

/** v1 list.csv を読み、email をキーに対象者行を返す（実測ヘッダに対応）。 */
function mig_read_targets(): array
{
    if (!is_file(V1_LIST_CSV)) {
        mig_die('list.csv が見つかりません: ' . V1_LIST_CSV);
    }
    $fh = fopen(V1_LIST_CSV, 'r');
    if ($fh === false) {
        mig_die('list.csv を開けません');
    }
    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        return [];
    }
    // 実測ヘッダ列名 → 内部キー（見出し文字列で位置を引く）
    $idx = static function (string $name) use ($header): ?int {
        $i = array_search($name, $header, true);
        return $i === false ? null : (int) $i;
    };
    $col = [
        'email' => $idx('メールアドレス（会社）'),
        'name' => $idx('表示氏名（姓名）'),
        'company' => $idx('会社名'),
        'title' => $idx('本務役職名称'),
    ];
    if ($col['email'] === null) {
        fclose($fh);
        mig_die('list.csv に「メールアドレス（会社）」列がありません');
    }
    $rows = [];
    while (($r = fgetcsv($fh)) !== false) {
        $email = trim((string) ($r[$col['email']] ?? ''));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            continue;
        }
        $rows[$email] = [
            'email' => $email,
            'name' => $col['name'] !== null ? trim((string) ($r[$col['name']] ?? '')) : '',
            'company' => $col['company'] !== null ? trim((string) ($r[$col['company']] ?? '')) : '',
            'title' => $col['title'] !== null ? trim((string) ($r[$col['title']] ?? '')) : '',
        ];
    }
    fclose($fh);
    return array_values($rows);
}

/** v1 campaigns.json を読み、v2 campaigns へ入れる最小情報を返す。 */
function mig_read_campaigns(): array
{
    if (!is_file(V1_CAMPAIGNS)) {
        return [];
    }
    $data = json_decode((string) file_get_contents(V1_CAMPAIGNS), true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $c) {
        if (!is_array($c) || !isset($c['name'])) {
            continue;
        }
        $out[] = [
            'name' => (string) $c['name'],
            'status' => !empty($c['is_active']) ? 'running' : 'done',
            'created_at' => (string) ($c['created_at'] ?? ''),
        ];
    }
    return $out;
}

function mig_tenant_id(string $slug): int
{
    $t = Db::one('SELECT id FROM tenants WHERE slug = ?', [$slug]);
    if ($t === null) {
        mig_die("テナント slug='$slug' が存在しません。先に UI/テナント管理で作成してください。");
    }
    return (int) $t['id'];
}

$args = mig_args();
if ($args['tenant'] === null || $args['tenant'] === '') {
    mig_die('--tenant=<slug> を指定してください');
}

$tenantId = mig_tenant_id($args['tenant']);
$targets = mig_read_targets();
$campaigns = mig_read_campaigns();

$mode = $args['commit'] ? 'COMMIT' : 'DRY-RUN';
echo "=== TET v1→v2 移行 [$mode] tenant={$args['tenant']} (id=$tenantId) ===\n";
echo '対象者(list.csv 有効email): ' . count($targets) . " 件\n";
echo 'キャンペーン(campaigns.json): ' . count($campaigns) . " 件\n\n";

// 既存 email との衝突を事前算出
$existing = [];
foreach (Db::all('SELECT email FROM targets WHERE tenant_id = ?', [$tenantId]) as $row) {
    $existing[(string) $row['email']] = true;
}
$toInsert = array_values(array_filter($targets, static fn ($t) => !isset($existing[$t['email']])));
$skip = count($targets) - count($toInsert);
echo '対象者: 新規 ' . count($toInsert) . " 件 / 既存衝突 skip $skip 件\n";
echo 'キャンペーン: 新規 ' . count($campaigns) . " 件（名称重複は許容）\n\n";

if (!$args['commit']) {
    echo "[DRY-RUN] DB は変更していません。実行するには --commit を付けてください。\n";
    if ($toInsert !== []) {
        echo "\n--- 投入予定 対象者(先頭5件) ---\n";
        foreach (array_slice($toInsert, 0, 5) as $t) {
            echo "  {$t['email']} / {$t['name']} / {$t['company']}\n";
        }
    }
    exit(0);
}

$insertedT = 0;
$insertedC = 0;
Db::tx(function () use ($tenantId, $toInsert, $campaigns, &$insertedT, &$insertedC): void {
    foreach ($toInsert as $t) {
        Db::run(
            'INSERT INTO targets (tenant_id, email, name, company, title) VALUES (?,?,?,?,?)',
            [$tenantId, $t['email'], $t['name'], $t['company'], $t['title']]
        );
        $insertedT++;
    }
    foreach ($campaigns as $c) {
        Db::run(
            'INSERT INTO campaigns (tenant_id, name, status, created_at) VALUES (?,?,?,COALESCE(NULLIF(?, \'\'), datetime(\'now\',\'localtime\')))',
            [$tenantId, $c['name'], $c['status'], $c['created_at']]
        );
        $insertedC++;
    }
});

echo "[COMMIT] 対象者 $insertedT 件 / キャンペーン $insertedC 件を投入しました。\n";
