<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * 不審メールの解析で照合する、テナントが登録した条件(G42)。表は suspicious_mail_rules。
 *
 * 種類:
 *   sender           送信者。@ を含めばアドレスの完全一致、含まなければドメイン(サブドメインも含む)
 *   subject_keyword  件名に含まれる語(大文字と小文字を区別しない)
 *   url_domain       本文の URL のホスト(サブドメインも含む)
 * 当たった時は SuspiciousMailAnalyzer が所見「登録した条件に一致」を足すだけで、自動で送ったり消したりはしない。
 * すべての操作はテナントの中だけで行う(ほかのテナントの条件は見えず、変えられず、照合にも使わない)。
 */
final class SuspiciousMailRules
{
    public const KINDS = ['sender', 'subject_keyword', 'url_domain'];
    public const MAX_RULES = 200;
    public const MAX_NAME = 100;
    public const MAX_VALUE = 200;
    public const MIN_KEYWORD = 2;

    /** @return list<array<string,mixed>> テナントの条件の一覧(新しい順ではなく登録順)。 */
    public static function all(int $tenantId): array
    {
        return Db::all('SELECT id, name, kind, value, is_active, created_by, created_at, updated_at FROM suspicious_mail_rules
                        WHERE tenant_id = ? ORDER BY id', [$tenantId]);
    }

    /** @return list<array{id:int,name:string,kind:string,value:string}> 照合に使う有効な条件。 */
    public static function active(int $tenantId): array
    {
        $rows = Db::all('SELECT id, name, kind, value FROM suspicious_mail_rules WHERE tenant_id = ? AND is_active = 1 ORDER BY id', [$tenantId]);
        return array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'],
            'kind' => (string) $r['kind'], 'value' => (string) $r['value']], $rows);
    }

    /**
     * 条件を作る。
     * @throws DomainException 入力が正しくない(400)、上限(409)
     */
    public static function create(int $tenantId, array $input, string $actor): int
    {
        $name = self::name($input['name'] ?? null);
        $kind = self::kind($input['kind'] ?? null);
        $value = self::value($kind, $input['value'] ?? null);
        $count = (int) Db::one('SELECT COUNT(*) n FROM suspicious_mail_rules WHERE tenant_id = ?', [$tenantId])['n'];
        if ($count >= self::MAX_RULES) {
            throw new DomainException('登録できる条件は' . self::MAX_RULES . '件までです', 409);
        }
        return Db::insert('INSERT INTO suspicious_mail_rules (tenant_id, name, kind, value, created_by) VALUES (?, ?, ?, ?, ?)',
            [$tenantId, $name, $kind, $value, mb_substr($actor, 0, 200)]);
    }

    /**
     * 名前、値、有効かどうかを変える(送られた項目だけ)。種類は変えない(値の形が変わるため作り直す)。
     * @throws DomainException 見つからない(404)、入力が正しくない(400)
     */
    public static function update(int $id, int $tenantId, array $input): array
    {
        $row = self::find($id, $tenantId);
        $name = array_key_exists('name', $input) ? self::name($input['name']) : (string) $row['name'];
        $value = array_key_exists('value', $input) ? self::value((string) $row['kind'], $input['value']) : (string) $row['value'];
        $active = (int) $row['is_active'];
        if (array_key_exists('is_active', $input)) {
            if (!is_bool($input['is_active'])) {
                throw new DomainException('is_active が不正です', 400);
            }
            $active = $input['is_active'] ? 1 : 0;
        }
        Db::run("UPDATE suspicious_mail_rules SET name = ?, value = ?, is_active = ?, updated_at = datetime('now','localtime')
                 WHERE id = ? AND tenant_id = ?", [$name, $value, $active, $id, $tenantId]);
        return self::find($id, $tenantId);
    }

    /** @throws DomainException 見つからない(404) */
    public static function delete(int $id, int $tenantId): void
    {
        self::find($id, $tenantId);
        Db::run('DELETE FROM suspicious_mail_rules WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
    }

    /** @throws DomainException 見つからない(404)。ほかのテナントの条件も「見つからない」にする。 */
    public static function find(int $id, int $tenantId): array
    {
        $row = Db::one('SELECT id, name, kind, value, is_active, created_by, created_at, updated_at FROM suspicious_mail_rules
                        WHERE id = ? AND tenant_id = ?', [$id, $tenantId]);
        if ($row === null) {
            throw new DomainException('条件が見つかりません', 404);
        }
        return $row;
    }

    /* ---------- 入力の検証 ---------- */

    private static function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '') {
            throw new DomainException('条件の名前を入力してください', 400);
        }
        if (mb_strlen($name, 'UTF-8') > self::MAX_NAME) {
            throw new DomainException('条件の名前は' . self::MAX_NAME . '文字以内にしてください', 400);
        }
        self::rejectControl($name, '条件の名前');
        return $name;
    }

    private static function kind(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::KINDS, true)) {
            throw new DomainException('条件の種類が不正です', 400);
        }
        return $value;
    }

    /** 種類ごとに値を検証し、照合しやすい形(小文字、前後の空白なし)にする。 */
    private static function value(string $kind, mixed $value): string
    {
        $raw = is_string($value) ? trim($value) : '';
        if ($raw === '') {
            throw new DomainException('照合する値を入力してください', 400);
        }
        if (mb_strlen($raw, 'UTF-8') > self::MAX_VALUE) {
            throw new DomainException('照合する値は' . self::MAX_VALUE . '文字以内にしてください', 400);
        }
        self::rejectControl($raw, '照合する値');
        if ($kind === 'subject_keyword') {
            if (mb_strlen($raw, 'UTF-8') < self::MIN_KEYWORD) {
                throw new DomainException('件名の語は' . self::MIN_KEYWORD . '文字以上にしてください', 400);
            }
            return $raw;
        }
        $lower = strtolower($raw);
        if ($kind === 'sender' && str_contains($lower, '@') && !str_starts_with($lower, '@')) {
            if (filter_var($lower, FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('送信者のメールアドレスの形が正しくありません', 400);
            }
            return $lower;
        }
        if ($kind === 'url_domain' && preg_match('~^https?://~', $lower) === 1) {
            $lower = (string) (parse_url($lower, PHP_URL_HOST) ?? '');
        }
        $domain = (string) preg_replace('/^(\*\.|@)/', '', $lower);
        if (!self::isDomain($domain)) {
            throw new DomainException('ドメインの形が正しくありません（例: example.com）', 400);
        }
        return $domain;
    }

    private static function isDomain(string $domain): bool
    {
        return strlen($domain) <= self::MAX_VALUE
            && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $domain) === 1;
    }

    private static function rejectControl(string $value, string $label): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new DomainException($label . 'に使えない文字が含まれています', 400);
        }
    }
}
