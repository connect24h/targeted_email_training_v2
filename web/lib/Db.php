<?php
/**
 * TET v2 データベースアクセス層。
 * - PDO(sqlite) 接続に WAL / busy_timeout / foreign_keys を必ず設定する。
 * - テナント分離は forTenant() が返すヘルパで WHERE tenant_id=? を機械付与する。
 * - 生 SQL を API 層に書かせず、prepared statement を徹底する。
 */
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;

    private const DB_PATH = '/opt/training/tet2-db/tet2.sqlite';

    /** 本番は DB_PATH 固定。TET2_DB_PATH が設定された時のみ上書き(隔離DBでのテスト用)。 */
    private static function dbPath(): string
    {
        $override = getenv('TET2_DB_PATH');
        return ($override !== false && $override !== '') ? $override : self::DB_PATH;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $pdo = new PDO('sqlite:' . self::dbPath(), null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        self::$pdo = $pdo;
        return $pdo;
    }

    /** 単一行取得。見つからなければ null。 */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** 複数行取得。 */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** INSERT/UPDATE/DELETE 実行。影響行数を返す。 */
    public static function run(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** INSERT 実行し、採番された id を返す。 */
    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * 読み取り後に大量書き込みする処理向けtransaction。
     * 最初にwriter予約を取るため、別接続の書き込み後にSQLITE_BUSYとなる
     * DEFERRED transactionのlock昇格競合を避ける。
     */
    public static function txImmediate(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public static function forTenant(int $tenantId): DbScope
    {
        return new DbScope($tenantId);
    }
}

final class DbScope
{
    private const ALLOWED = ['targets', 'groups', 'templates', 'campaigns', 'events', 'users'];

    public function __construct(private int $tenantId)
    {
    }

    public function assertTable(string $table): void
    {
        if (!in_array($table, self::ALLOWED, true)) {
            throw new InvalidArgumentException('forTenant 対象外のテーブルです: ' . $table);
        }
    }

    public function find(string $table, int $id): ?array
    {
        $this->assertTable($table);
        return Db::one("SELECT * FROM {$table} WHERE id = ? AND tenant_id = ?", [$id, $this->tenantId]);
    }

    public function all(string $table, string $extraWhere = '', array $extraParams = [], string $order = ''): array
    {
        $this->assertTable($table);
        $sql = "SELECT * FROM {$table} WHERE tenant_id = ?";
        if ($extraWhere !== '') {
            $sql .= " AND ({$extraWhere})";
        }
        if ($order !== '') {
            $this->assertOrder($order);
            $sql .= " ORDER BY {$order}";
        }
        return Db::all($sql, array_merge([$this->tenantId], $extraParams));
    }

    public function count(string $table, string $extraWhere = '', array $extraParams = []): int
    {
        $this->assertTable($table);
        $sql = "SELECT COUNT(*) AS count FROM {$table} WHERE tenant_id = ?";
        if ($extraWhere !== '') {
            $sql .= " AND ({$extraWhere})";
        }
        $row = Db::one($sql, array_merge([$this->tenantId], $extraParams));
        return (int) ($row['count'] ?? 0);
    }

    public function update(string $table, int $id, array $fields): int
    {
        $this->assertTable($table);
        $this->assertFields($fields, false);
        $set = implode(', ', array_map(fn(string $key): string => "{$key} = ?", array_keys($fields)));
        $params = array_merge(array_values($fields), [$id, $this->tenantId]);
        return Db::run("UPDATE {$table} SET {$set} WHERE id = ? AND tenant_id = ?", $params);
    }

    public function delete(string $table, int $id): int
    {
        $this->assertTable($table);
        return Db::run("DELETE FROM {$table} WHERE id = ? AND tenant_id = ?", [$id, $this->tenantId]);
    }

    public function insert(string $table, array $fields): int
    {
        $this->assertTable($table);
        if (array_key_exists('tenant_id', $fields)) {
            throw new InvalidArgumentException('tenant_id は forTenant が自動設定します');
        }
        $fields['tenant_id'] = $this->tenantId;
        $this->assertFields($fields, true);
        $columns = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        return Db::insert("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})", array_values($fields));
    }

    public function findTemplate(int $id, bool $includeShared): ?array
    {
        if ($includeShared) {
            return Db::one(
                'SELECT * FROM templates WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)',
                [$id, $this->tenantId]
            );
        }
        return $this->find('templates', $id);
    }

    public function allTemplates(bool $includeShared, string $extraWhere = '', array $extraParams = []): array
    {
        $condition = $includeShared ? '(tenant_id = ? OR tenant_id IS NULL)' : 'tenant_id = ?';
        $sql = "SELECT * FROM templates WHERE {$condition}";
        if ($extraWhere !== '') {
            $sql .= " AND ({$extraWhere})";
        }
        return Db::all($sql, array_merge([$this->tenantId], $extraParams));
    }

    private function assertOrder(string $order): void
    {
        foreach (explode(',', $order) as $part) {
            $part = trim($part);
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*( +(ASC|DESC))?$/i', $part)) {
                throw new InvalidArgumentException('ORDER BY に不正な指定があります');
            }
        }
    }

    private function assertFields(array $fields, bool $allowTenantId): void
    {
        if ($fields === []) {
            throw new InvalidArgumentException('更新または登録するフィールドがありません');
        }
        foreach (array_keys($fields) as $key) {
            if (!$allowTenantId && $key === 'tenant_id') {
                throw new InvalidArgumentException('tenant_id は更新できません');
            }
            if (!is_string($key) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                throw new InvalidArgumentException('カラム名に不正な指定があります');
            }
        }
    }
}
