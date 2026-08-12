<?php
declare(strict_types=1);

final class MigrationRunner
{
    private const VERSIONS = [
        '20260808-current-schema',
        '20260809-campaign-automations',
        '20260809-campaign-rotation',
        '20260809-awareness-participant-integration',
        '20260809-targets-archived-at',
        '20260809-targets-is-test',
        '20260810-position-masters',
        '20260810-all-members-group',
        '20260810-elearning-materials',
    ];

    /**
     * 役職マスタの初期データ。ゴールドウイン(slug=goldwin)の役職体系。
     * note='推定' の行は提供リストに無く、役職名から推定したもの(画面で修正可能)。
     *
     * @var list<array{0:string,1:string,2:?string}> [title, category, note]
     */
    private const GOLDWIN_POSITIONS = [
        // --- 役員 ---
        ['名誉会長', '役員', null],
        ['代表取締役社長', '役員', null],
        ['取締役', '役員', null],
        ['社外取締役', '役員', null],
        ['常勤監査役', '役員', null],
        ['社外監査役', '役員', null],
        ['常勤顧問', '役員', null],
        ['常勤参事', '役員', null],
        ['常務理事', '役員', null],
        ['本部長', '役員', null],
        ['グループ会社社長', '役員', null],
        ['グループ会社常勤取締役', '役員', null],
        ['執行役員ＣＳＬＯ', '役員', '推定'],
        ['執行役員ＣＨＲＯ', '役員', '推定'],
        ['グループ会社会長', '役員', '推定'],
        ['総経理', '役員', '推定'],
        ['役員', '役員', '推定'],
        // --- 管理職 ---
        ['室長', '管理職', null],
        ['部長', '管理職', null],
        ['担当部長', '管理職', null],
        ['副部長', '管理職', null],
        ['副本部長', '管理職', null],
        ['マネージャー', '管理職', null],
        ['エリア長', '管理職', null],
        ['店長', '管理職', null],
        ['副店長', '管理職', null],
        ['ＤＢ', '管理職', null],
        ['マーケティングディレクター', '管理職', null],
        ['Ｄ２Ｃセールスマネージャー', '管理職', null],
        ['スーパーバイザー', '管理職', '推定'],
        ['管理総監', '管理職', '推定'],
        ['管理職', '管理職', '推定'],
        // --- 一般従業員 ---
        ['一般従業員', '一般従業員', null],
        ['エキスパート', '一般従業員', null],
        ['シニアエキスパート', '一般従業員', null],
        ['リーダー', '一般従業員', null],
        ['ＭＤ', '一般従業員', null],
        ['セールスマイスター', '一般従業員', null],
        ['コファウンダー', '一般従業員', null],
        ['社員', '一般従業員', '推定'],
    ];

    private string $dbPath;

    public function __construct(string $dbPath)
    {
        if ($dbPath === '' || !str_starts_with($dbPath, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('DB pathは絶対pathで明示してください');
        }
        $realPath = realpath($dbPath);
        if ($realPath === false || !is_file($realPath)) {
            throw new InvalidArgumentException('指定DBが存在しません');
        }
        $this->dbPath = $realPath;
    }

    /** @return list<string> */
    public function pending(): array
    {
        $pdo = $this->connect(true);
        if (!$this->tableExists($pdo, 'schema_migrations')) {
            return self::VERSIONS;
        }
        $pending = [];
        $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = ?');
        foreach (self::VERSIONS as $version) {
            $stmt->execute([$version]);
            if ($stmt->fetchColumn() === false) {
                $pending[] = $version;
            }
        }
        return $pending;
    }

    public function migrate(): int
    {
        $pending = $this->pending();
        if ($pending === []) {
            return 0;
        }
        $pdo = $this->connect(false);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
        try {
            foreach ($pending as $version) {
                $this->apply($pdo, $version);
                $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
                $stmt->execute([$version]);
            }
            if ($pdo->query('PRAGMA foreign_key_check')->fetchAll() !== []) {
                throw new RuntimeException('migration後にforeign key違反を検出しました');
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        return count($pending);
    }

    private function apply(PDO $pdo, string $version): void
    {
        if ($version === '20260808-current-schema') {
            $this->applyCurrentSchema($pdo);
            return;
        }
        if ($version === '20260809-campaign-automations') {
            $pdo->exec($this->readSchema('schema-automation.sql'));
            return;
        }
        if ($version === '20260809-campaign-rotation') {
            $this->applyCampaignRotation($pdo);
            return;
        }
        if ($version === '20260809-awareness-participant-integration') {
            $this->applyAwarenessParticipantIntegration($pdo);
            return;
        }
        if ($version === '20260809-targets-archived-at') {
            $this->ensureAdditiveColumns($pdo);
            return;
        }
        if ($version === '20260809-targets-is-test') {
            $this->ensureAdditiveColumns($pdo);
            return;
        }
        if ($version === '20260810-position-masters') {
            $this->applyPositionMasters($pdo);
            return;
        }
        if ($version === '20260810-all-members-group') {
            $pdo->exec("UPDATE groups SET kind = 'all' WHERE name = '全職員' AND status = 'active'");
            return;
        }
        if ($version === '20260810-elearning-materials') {
            $this->applyElearningMaterials($pdo);
            return;
        }
        throw new RuntimeException("未知のmigrationです: {$version}");
    }

    private function applyElearningMaterials(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS edu_materials (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, title TEXT NOT NULL,
            description TEXT, slides TEXT NOT NULL, is_active INTEGER NOT NULL DEFAULT 1,
            is_shared INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_edu_materials_tenant
            ON edu_materials(tenant_id, is_active)');
        if (!$this->columnExists($pdo, 'edu_deliveries', 'material_id')) {
            $pdo->exec('ALTER TABLE edu_deliveries ADD COLUMN material_id INTEGER
                REFERENCES edu_materials(id)');
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS edu_delivery_targets (
            delivery_id INTEGER NOT NULL, target_id INTEGER NOT NULL,
            PRIMARY KEY (delivery_id, target_id),
            FOREIGN KEY (delivery_id) REFERENCES edu_deliveries(id) ON DELETE CASCADE,
            FOREIGN KEY (target_id) REFERENCES targets(id))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_edu_dt_delivery
            ON edu_delivery_targets(delivery_id)');
        $this->seedPhishingRemedialMaterial($pdo);
    }

    private function seedPhishingRemedialMaterial(PDO $pdo): void
    {
        $title = '標的型メール訓練 フォローアップ基礎';
        $exists = $pdo->prepare('SELECT 1 FROM edu_materials WHERE tenant_id IS NULL AND title = ?');
        $exists->execute([$title]);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        $slides = [
            ['title' => '訓練は失敗ではなく学習の入口です', 'body' => "訓練メールを開いた経験を、次の攻撃を止める判断力に変えます。\n落ち着いて、どこに違和感があったかを確認しましょう。"],
            ['title' => '送信者を表示名だけで判断しない', 'body' => "表示名が知人や取引先でも、実際のメールアドレスとドメインを確認します。\n返信や転送を急がせる文面にも注意します。"],
            ['title' => 'リンク先を開く前に確認する', 'body' => "リンクにマウスを合わせ、表示先のドメインを確認します。\n短縮URLや綴りの似た偽ドメインは、正規サイトを別途開いて確認します。"],
            ['title' => '添付ファイルと認証要求を疑う', 'body' => "予期しない添付ファイルや、突然のパスワード入力要求は開かないでください。\n判断に迷ったら送信者へ別経路で確認します。"],
            ['title' => '気づいたらすぐ報告する', 'body' => "クリックや入力をしても、隠さず速やかに管理者へ報告してください。\n早い報告が被害拡大を防ぎます。"],
        ];
        $insert = $pdo->prepare('INSERT INTO edu_materials
            (tenant_id, title, description, slides, is_active, is_shared)
            VALUES (NULL, ?, ?, ?, 1, 1)');
        $insert->execute([$title, '標的型メール訓練で防衛失敗した受講者向けの短時間教材',
            json_encode($slides, JSON_UNESCAPED_UNICODE)]);
    }

    private function applyCampaignRotation(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'campaign_automations', 'assignment_mode')) {
            $pdo->exec("ALTER TABLE campaign_automations ADD COLUMN assignment_mode TEXT NOT NULL
                DEFAULT 'static' CHECK (assignment_mode IN ('static','rotate'))");
        }
        if (!$this->columnExists($pdo, 'campaign_automations', 'max_occurrences')) {
            $pdo->exec('ALTER TABLE campaign_automations ADD COLUMN max_occurrences INTEGER
                CHECK (max_occurrences BETWEEN 1 AND 120)');
        }
    }

    private function applyAwarenessParticipantIntegration(PDO $pdo): void
    {
        if (!$this->columnExists($pdo, 'groups', 'status')) {
            $pdo->exec("ALTER TABLE groups ADD COLUMN status TEXT NOT NULL DEFAULT 'active'
                CHECK (status IN ('active','archived'))");
        }
        if (!$this->columnExists($pdo, 'groups', 'archived_at')) {
            $pdo->exec('ALTER TABLE groups ADD COLUMN archived_at TEXT');
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS integration_idempotency_keys (
            tenant_id INTEGER NOT NULL, idempotency_key TEXT NOT NULL, action TEXT NOT NULL,
            request_hash TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'processing\'
                CHECK (status IN (\'processing\',\'completed\')),
            response_body TEXT, created_at TEXT NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
            expires_at TEXT NOT NULL, PRIMARY KEY (tenant_id, idempotency_key),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_integration_idempotency_expiry
            ON integration_idempotency_keys(expires_at)');
    }

    private function applyCurrentSchema(PDO $pdo): void
    {
        $this->ensureAdditiveColumns($pdo);
        $this->rebuildCampaignTargetsIfNeeded($pdo);
        $this->rebuildEducationSharingIfNeeded($pdo);
        $pdo->exec($this->readSchema('schema.sql'));
        $pdo->exec($this->readSchema('schema-edu.sql'));
    }

    /**
     * 役職マスタを作成し、旧カテゴリを正規化してゴールドウインの初期マスタを適用する。
     */
    private function applyPositionMasters(PDO $pdo): void
    {
        $pdo->exec($this->readSchema('schema-position.sql'));
        $pdo->exec("UPDATE targets SET position_category = '一般従業員' WHERE position_category = '社員'");

        $tenantStmt = $pdo->prepare('SELECT id FROM tenants WHERE slug = ?');
        $tenantStmt->execute(['goldwin']);
        $tenantId = $tenantStmt->fetchColumn();
        if ($tenantId === false) {
            return;
        }
        $tenantId = (int) $tenantId;

        $insert = $pdo->prepare(
            'INSERT OR IGNORE INTO position_masters (tenant_id, title, category, sort_order, note)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach (self::GOLDWIN_POSITIONS as $index => [$title, $category, $note]) {
            $insert->execute([$tenantId, $title, $category, $index, $note]);
        }

        $apply = $pdo->prepare(
            "UPDATE targets
                SET position_category = (
                    SELECT pm.category FROM position_masters pm
                     WHERE pm.tenant_id = targets.tenant_id AND pm.title = targets.title
                )
              WHERE tenant_id = ?
                AND EXISTS (
                    SELECT 1 FROM position_masters pm
                     WHERE pm.tenant_id = targets.tenant_id AND pm.title = targets.title
                )"
        );
        $apply->execute([$tenantId]);
    }

    private function ensureAdditiveColumns(PDO $pdo): void
    {
        $columns = [
            'campaigns' => [
                'beacon_base' => 'TEXT',
                'content_delivery' => "TEXT NOT NULL DEFAULT 'distribute'",
                'deleted_at' => 'TEXT DEFAULT NULL',
                'test_redirect_emails' => 'TEXT DEFAULT NULL',
            ],
            'targets' => [
                'position_category' => 'TEXT',
                'tenant_no' => 'INTEGER',
                'archived_at' => 'TEXT DEFAULT NULL',
                'is_test' => 'INTEGER NOT NULL DEFAULT 0',
            ],
            'templates' => ['scenario_key' => 'TEXT'],
            'campaign_targets' => ['content_no' => 'INTEGER'],
            'campaign_contents' => ['suppress_prefill_email' => 'INTEGER NOT NULL DEFAULT 0'],
            'edu_categories' => ['is_shared' => 'INTEGER NOT NULL DEFAULT 0'],
            'edu_questions' => ['is_shared' => 'INTEGER NOT NULL DEFAULT 0'],
            'edu_assignments' => ['last_reminded_at' => 'TEXT'],
        ];
        foreach ($columns as $table => $definitions) {
            if (!$this->tableExists($pdo, $table)) {
                continue;
            }
            foreach ($definitions as $column => $definition) {
                if (!$this->columnExists($pdo, $table, $column)) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                }
            }
        }
    }

    private function rebuildCampaignTargetsIfNeeded(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'campaign_targets') || !$this->hasLegacyTargetUnique($pdo)) {
            return;
        }
        $pdo->exec('CREATE TABLE campaign_targets_migration (
            id INTEGER PRIMARY KEY AUTOINCREMENT, campaign_id INTEGER NOT NULL,
            target_id INTEGER NOT NULL, tracking_id TEXT NOT NULL UNIQUE, koban INTEGER,
            auth_flag INTEGER, from_address TEXT, attachment_path TEXT,
            send_status TEXT NOT NULL DEFAULT \'pending\', sent_at TEXT, content_no INTEGER,
            UNIQUE (campaign_id, target_id, content_no),
            FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
            FOREIGN KEY (target_id) REFERENCES targets(id))');
        $pdo->exec('INSERT INTO campaign_targets_migration
            (id, campaign_id, target_id, tracking_id, koban, auth_flag, from_address,
             attachment_path, send_status, sent_at, content_no)
            SELECT id, campaign_id, target_id, tracking_id, koban, auth_flag, from_address,
                   attachment_path, send_status, sent_at, content_no FROM campaign_targets');
        $pdo->exec('DROP TABLE campaign_targets');
        $pdo->exec('ALTER TABLE campaign_targets_migration RENAME TO campaign_targets');
    }

    private function rebuildEducationSharingIfNeeded(PDO $pdo): void
    {
        if (!$this->needsNullableTenant($pdo, 'edu_categories')
            && !$this->needsNullableTenant($pdo, 'edu_questions')) {
            return;
        }
        $pdo->exec('CREATE TABLE edu_categories_migration (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name TEXT NOT NULL,
            slug TEXT NOT NULL, color TEXT, sort_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1, is_shared INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id))');
        $pdo->exec('CREATE TABLE edu_questions_migration (
            id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, category_id INTEGER NOT NULL,
            title TEXT NOT NULL, question_type TEXT NOT NULL DEFAULT \'single_choice\',
            options TEXT NOT NULL, correct_answer TEXT NOT NULL, explanation TEXT,
            difficulty INTEGER NOT NULL DEFAULT 1, is_active INTEGER NOT NULL DEFAULT 1,
            is_shared INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\',\'localtime\')),
            FOREIGN KEY (tenant_id) REFERENCES tenants(id),
            FOREIGN KEY (category_id) REFERENCES edu_categories_migration(id) ON DELETE CASCADE)');
        $pdo->exec('INSERT INTO edu_categories_migration
            SELECT id, tenant_id, name, slug, color, sort_order, is_active, is_shared, created_at
            FROM edu_categories');
        $pdo->exec('INSERT INTO edu_questions_migration
            SELECT id, tenant_id, category_id, title, question_type, options, correct_answer,
                   explanation, difficulty, is_active, is_shared, created_at FROM edu_questions');
        $pdo->exec('DROP TABLE edu_questions');
        $pdo->exec('DROP TABLE edu_categories');
        $pdo->exec('ALTER TABLE edu_categories_migration RENAME TO edu_categories');
        $pdo->exec('ALTER TABLE edu_questions_migration RENAME TO edu_questions');
    }

    private function hasLegacyTargetUnique(PDO $pdo): bool
    {
        foreach ($pdo->query('PRAGMA index_list(campaign_targets)')->fetchAll() as $index) {
            if ((int) $index['unique'] !== 1) {
                continue;
            }
            $name = str_replace("'", "''", (string) $index['name']);
            $columns = $pdo->query("PRAGMA index_info('{$name}')")->fetchAll(PDO::FETCH_COLUMN, 2);
            if ($columns === ['campaign_id', 'target_id']) {
                return true;
            }
        }
        return false;
    }

    private function needsNullableTenant(PDO $pdo, string $table): bool
    {
        if (!$this->tableExists($pdo, $table)) {
            return false;
        }
        foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $column) {
            if ($column['name'] === 'tenant_id') {
                return (int) $column['notnull'] === 1;
            }
        }
        return false;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $info) {
            if ($info['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    private function connect(bool $readOnly): PDO
    {
        $dsn = $readOnly ? 'sqlite:file:' . $this->dbPath . '?mode=ro' : 'sqlite:' . $this->dbPath;
        return new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function readSchema(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/' . $name);
        if ($contents === false) {
            throw new RuntimeException("schema読込失敗: {$name}");
        }
        return $contents;
    }
}
