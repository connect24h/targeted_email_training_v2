<?php
declare(strict_types=1);

/**
 * 部署の階層(C3、G14)。部署の文字列を「本部/部/課」のように「/」(全角の「／」も)で区切って登録すれば、
 * レポートの部署の表を1段目、2段目、全部でまとめられる。新しいマスタは作らず、targets.department をそのまま使う。
 *
 * - 段は区切りで分け、段の前後の空白を除き、空の段は無視する(「/営業本部//東日本営業部/」は2段)。
 * - 1段目は最初の段、2段目は最初の2段を「 / 」でつなぐ。段が足りない部署はある段だけ。
 * - 0(全部)は今までと同じく、前後の空白だけを除いた文字列(区切りの書き方も変えない)。
 * - 空の部署(段が1つもない部署を含む)は「(未設定)」。
 */
final class DeptPath
{
    public const UNSET_LABEL = '(未設定)';
    /** 選べる段(0 は全部)。 */
    public const LEVELS = [0, 1, 2];

    /** @return list<string> 段(前後の空白を除き、空の段は除く) */
    public static function segments(?string $department): array
    {
        $parts = preg_split('~[/／]~u', (string) $department) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = self::trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    /** 表に出す部署の名前。$level は 0(全部)、1、2。 */
    public static function label(?string $department, int $level): string
    {
        if ($level === 0) {
            $department = trim((string) $department);
            return $department === '' ? self::UNSET_LABEL : $department;
        }
        $segments = array_slice(self::segments($department), 0, $level);
        return $segments === [] ? self::UNSET_LABEL : implode(' / ', $segments);
    }

    /**
     * クエリの dept_level を段にする。なし、空、'0'、'all' は 0(今までと同じ)。
     * @throws InvalidArgumentException 1、2 以外の値
     */
    public static function parseLevel(mixed $raw): int
    {
        if ($raw === null || $raw === '' || $raw === 'all') {
            return 0;
        }
        if (is_string($raw) && preg_match('/^[0-9]$/', $raw) === 1 && in_array((int) $raw, self::LEVELS, true)) {
            return (int) $raw;
        }
        throw new InvalidArgumentException('dept_level は 0、1、2、all のどれかです');
    }

    /**
     * 行を段でまとめ直す。$sumFields の数を足し、それ以外は捨てる(率は呼び出し側で足した数から計算し直す)。
     * 返す行は [$keyField => 名前] + 足した数。並びは名前の順(親の直後に子が来る)。
     * @param list<array<string,mixed>> $rows
     * @param list<string> $sumFields
     * @return list<array<string,mixed>>
     */
    public static function regroup(array $rows, string $keyField, int $level, array $sumFields): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $label = self::label(isset($row[$keyField]) ? (string) $row[$keyField] : null, $level);
            $groups[$label] ??= array_fill_keys($sumFields, 0);
            foreach ($sumFields as $field) {
                $groups[$label][$field] += $row[$field] ?? 0;
            }
        }
        ksort($groups, SORT_STRING);
        $out = [];
        foreach ($groups as $label => $sums) {
            $out[] = [$keyField => (string) $label] + $sums;
        }
        return $out;
    }

    /**
     * テナントの部署の最も深い段の数。テスト用と削除済みの対象者は数えない(レポートの対象と同じ)。
     * 画面は2段以上の部署があるときだけ「部署のまとめ方」を出す。
     */
    public static function tenantMaxDepth(int $tenantId): int
    {
        $rows = Db::all(
            "SELECT DISTINCT department FROM targets
             WHERE tenant_id = ? AND is_test = 0 AND status = 'active'
               AND (department LIKE '%/%' OR department LIKE '%／%')",
            [$tenantId]
        );
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, count(self::segments((string) $row['department'])));
        }
        return $max;
    }

    /** 前後の空白(全角の空白を含む)を除く。 */
    private static function trim(string $s): string
    {
        return preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $s) ?? $s;
    }
}
