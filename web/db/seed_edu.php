<?php
/**
 * 教材(edu_categories / edu_questions)を指定テナントに投入する。CLI 実行専用。
 *
 *   php seed_edu.php <tenant_id>
 *   TET2_DB_PATH=/tmp/xxx.sqlite php seed_edu.php <tenant_id>   # 隔離DB検証用
 *
 * - 教材は SecurityAwareness の100問CSV(IPA10大脅威準拠12カテゴリ)を移植。
 * - 冪等: 同一テナントへ再実行しても二重投入しない(categories は UNIQUE(tenant_id,slug)、
 *   questions は tenant_id+category_id+title の一致で既存判定)。
 * - 本番 lib/Db.php はDBパスをハードコードしているため、検証時にコピーDBへ向けられるよう
 *   このスクリプトは Db.php に依存せず独自 PDO を張る(TET2_DB_PATH で上書き可)。
 * - 接続 PRAGMA は本番 Db.php と同一(WAL / busy_timeout=5000 / foreign_keys=ON)。
 */
declare(strict_types=1);

const DEFAULT_DB_PATH = '/opt/training/tet2-db/tet2.sqlite';
const CSV_PATH        = '/root/SecurityAwareness/data/questions-seed.csv';

// スラッグ → 日本語表示名
const CATEGORY_NAMES = [
    'phishing'            => 'フィッシング',
    'malware'             => 'マルウェア',
    'password-management' => 'パスワード管理',
    'social-engineering'  => 'ソーシャルエンジニアリング',
    'data-leakage'        => '情報漏洩',
    'incident-response'   => 'インシデント対応',
    'cloud-security'      => 'クラウドセキュリティ',
    'mobile-security'     => 'モバイルセキュリティ',
    'physical-security'   => '物理セキュリティ',
    'remote-work'         => 'リモートワーク',
    'sns-security'        => 'SNSセキュリティ',
    'compliance'          => 'コンプライアンス',
];

// --- 引数 ---
$tenantId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($tenantId <= 0) {
    fwrite(STDERR, "使用法: php seed_edu.php <tenant_id>  (全テナント無差別投入を防ぐため tenant_id は必須)\n");
    exit(1);
}

$dbPath = getenv('TET2_DB_PATH') ?: DEFAULT_DB_PATH;
if (!is_readable(CSV_PATH)) {
    fwrite(STDERR, 'CSV が読めません: ' . CSV_PATH . "\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$pdo->exec('PRAGMA journal_mode = WAL');
$pdo->exec('PRAGMA busy_timeout = 5000');
$pdo->exec('PRAGMA foreign_keys = ON');

// テナント存在確認(FK 違反を早期に分かりやすく)
$stmt = $pdo->prepare('SELECT id FROM tenants WHERE id = ?');
$stmt->execute([$tenantId]);
if ($stmt->fetch() === false) {
    fwrite(STDERR, "tenant_id={$tenantId} が tenants に存在しません\n");
    exit(1);
}

// --- CSV 読み込み ---
$fh = new SplFileObject(CSV_PATH, 'r');
$fh->setFlags(SplFileObject::READ_CSV);

$rows = [];
$header = null;
foreach ($fh as $cells) {
    if ($cells === [null] || $cells === false) {
        continue; // 末尾空行
    }
    if ($header === null) {
        // BOM 除去
        if (isset($cells[0])) {
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
        }
        $header = $cells;
        continue;
    }
    if (count(array_filter($cells, fn($v) => $v !== null && $v !== '')) === 0) {
        continue;
    }
    $rows[] = $cells;
}

// 列: 0=カテゴリスラッグ 1=問題タイプ 2=難易度 3=タイトル 4-7=選択肢1-4 8=正解index 9=解説
$catInsert = $pdo->prepare(
    'INSERT OR IGNORE INTO edu_categories (tenant_id, name, slug, sort_order, is_active)
     VALUES (?, ?, ?, ?, 1)'
);
$catFind = $pdo->prepare('SELECT id FROM edu_categories WHERE tenant_id = ? AND slug = ?');
$qFind   = $pdo->prepare('SELECT id FROM edu_questions WHERE tenant_id = ? AND category_id = ? AND title = ?');
$qInsert = $pdo->prepare(
    'INSERT INTO edu_questions (tenant_id, category_id, title, question_type, options, correct_answer, explanation, difficulty, is_active)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
);

$catInserted = 0;
$catTotal    = 0;
$qInserted   = 0;
$qSkipped    = 0;
$catIdCache  = [];
$sortOrder   = 0;

$pdo->beginTransaction();
try {
    foreach ($rows as $r) {
        $slug = trim((string) ($r[0] ?? ''));
        if ($slug === '') {
            continue;
        }
        // カテゴリ確定(初出のみ INSERT、sort_order は初出順)
        if (!isset($catIdCache[$slug])) {
            $name = CATEGORY_NAMES[$slug] ?? $slug;
            $catInsert->execute([$tenantId, $name, $slug, $sortOrder]);
            if ($catInsert->rowCount() > 0) {
                $catInserted++;
            }
            $catFind->execute([$tenantId, $slug]);
            $catRow = $catFind->fetch();
            $catIdCache[$slug] = (int) $catRow['id'];
            $catTotal++;
            $sortOrder++;
        }
        $categoryId = $catIdCache[$slug];

        $type       = trim((string) ($r[1] ?? 'single_choice'));
        $difficulty = (int) ($r[2] ?? 1);
        $title      = trim((string) ($r[3] ?? ''));
        if ($title === '') {
            continue;
        }

        // 選択肢: 非空のみ JSON 配列化
        $options = [];
        foreach ([4, 5, 6, 7] as $ci) {
            $opt = isset($r[$ci]) ? trim((string) $r[$ci]) : '';
            if ($opt !== '') {
                $options[] = $opt;
            }
        }

        // 正解: CSV は 0始まり単一index。将来の複数選択に備え配列で統一
        $correctIdx = (int) ($r[8] ?? 0);
        $correct    = [$correctIdx];

        $explanation = isset($r[9]) ? trim((string) $r[9]) : '';

        // 冪等: 既存(tenant+category+title)ならスキップ
        $qFind->execute([$tenantId, $categoryId, $title]);
        if ($qFind->fetch() !== false) {
            $qSkipped++;
            continue;
        }

        $qInsert->execute([
            $tenantId,
            $categoryId,
            $title,
            $type,
            json_encode($options, JSON_UNESCAPED_UNICODE),
            json_encode($correct, JSON_UNESCAPED_UNICODE),
            $explanation,
            $difficulty,
        ]);
        $qInserted++;
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, '投入失敗: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "対象テナント: {$tenantId}\n";
echo "categories: {$catTotal}件 (新規 {$catInserted})\n";
echo "questions: 新規 {$qInserted} / スキップ {$qSkipped} (既存)\n";
