<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * 分野のタグ(G09)。2階層(親と子)で、1つの設問に複数付けられる。
 *
 * - 見えるタグ: 共有(tenant_id NULL)と自テナントのタグ。superadmin の共有スコープ(番兵 0)では共有だけになる。
 * - 設問に付けられるタグ: 共有の設問には共有のタグだけ(テナントのタグの名前をほかの組織に見せないため)。
 *   テナントの設問には、共有かそのテナントのタグ。
 * - Excel の表記: 親は「名前」、子は「親の名前 > 子の名前」。1セルに改行で区切って複数。
 */
final class EduTags
{
    public const PATH_SEPARATOR = ' > ';
    public const MAX_NAME = 100;
    public const MAX_PER_QUESTION = 20;

    /** 改行などの空白を1つにし、区切りの > を全角にする(MigrationRunner::eduTagName と同じ)。空なら ''。 */
    public static function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', str_replace('>', '＞', $name)));
    }

    /**
     * 見えるタグを、親の並び順(sort_order、id)で、各親の直後にその子を並べて返す。
     * 各行に path(「親 > 子」)、is_shared、question_count(見える設問のうち、そのタグが付いた数)を足す。
     * @return list<array<string,mixed>>
     */
    public static function visible(int $tenantId): array
    {
        $rows = Db::all(
            'SELECT g.id, g.tenant_id, g.parent_id, g.name, g.description, g.sort_order, g.created_at,
                    (SELECT COUNT(*) FROM edu_question_tags qt
                     INNER JOIN edu_questions q ON q.id = qt.question_id
                     WHERE qt.tag_id = g.id AND (q.tenant_id = ? OR q.tenant_id IS NULL)) AS question_count
             FROM edu_tags g
             WHERE g.tenant_id = ? OR g.tenant_id IS NULL
             ORDER BY g.sort_order, g.id',
            [$tenantId, $tenantId]
        );
        $children = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] !== null) {
                $children[(int) $row['parent_id']][] = $row;
            }
        }
        $out = [];
        foreach ($rows as $row) {
            if ($row['parent_id'] !== null) {
                continue;
            }
            $out[] = self::present($row, null);
            foreach ($children[(int) $row['id']] ?? [] as $child) {
                $out[] = self::present($child, $row);
            }
        }
        return $out;
    }

    /** 見えるタグ1件(path つき)。見えなければ null。 */
    public static function find(int $id, int $tenantId): ?array
    {
        $row = Db::one('SELECT * FROM edu_tags WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)', [$id, $tenantId]);
        if ($row === null) {
            return null;
        }
        $parent = $row['parent_id'] !== null ? Db::one('SELECT * FROM edu_tags WHERE id = ?', [(int) $row['parent_id']]) : null;
        return self::present($row, $parent);
    }

    /**
     * 設問ごとの見えるタグ(並びは visible と同じ)。
     * @param list<int> $questionIds
     * @return array<int, list<array{id:int,parent_id:?int,name:string,path:string}>>
     */
    public static function forQuestions(array $questionIds, int $tenantId): array
    {
        if ($questionIds === []) {
            return [];
        }
        $order = [];
        foreach (self::visible($tenantId) as $i => $tag) {
            $order[(int) $tag['id']] = ['rank' => $i, 'tag' => [
                'id' => (int) $tag['id'], 'parent_id' => $tag['parent_id'] !== null ? (int) $tag['parent_id'] : null,
                'name' => (string) $tag['name'], 'path' => (string) $tag['path'],
            ]];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($questionIds)), 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            foreach (Db::all("SELECT question_id, tag_id FROM edu_question_tags WHERE question_id IN ($ph)", $chunk) as $link) {
                $entry = $order[(int) $link['tag_id']] ?? null;
                if ($entry !== null) {
                    $out[(int) $link['question_id']][$entry['rank']] = $entry['tag'];
                }
            }
        }
        foreach ($out as $qid => $tags) {
            ksort($tags);
            $out[$qid] = array_values($tags);
        }
        return $out;
    }

    /**
     * 画面から来たタグの id の配列を確かめる。$questionTenant は設問の持ち主(NULL は共有の設問)。
     * @return list<int>
     * @throws InvalidArgumentException 画面に出せる理由つき
     */
    public static function assignable(mixed $ids, ?int $questionTenant, int $tenantId): array
    {
        if (!is_array($ids) || count($ids) > self::MAX_PER_QUESTION) {
            throw new InvalidArgumentException('tag_ids は' . self::MAX_PER_QUESTION . '件までの配列で指定してください');
        }
        $out = [];
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException('tag_ids が不正です');
            }
            $tag = Db::one('SELECT id, tenant_id FROM edu_tags WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)', [$id, $tenantId]);
            if ($tag === null) {
                throw new InvalidArgumentException('分野のタグが見つかりません');
            }
            self::assertScope($tag['tenant_id'] !== null ? (int) $tag['tenant_id'] : null, $questionTenant);
            $out[$id] = $id;
        }
        return array_values($out);
    }

    /** 設問のタグを置き換える(呼ぶ側で assignable を通した id だけを渡す)。 */
    public static function replaceForQuestion(int $questionId, array $tagIds): void
    {
        Db::run('DELETE FROM edu_question_tags WHERE question_id = ?', [$questionId]);
        foreach ($tagIds as $tagId) {
            Db::run('INSERT OR IGNORE INTO edu_question_tags (question_id, tag_id) VALUES (?, ?)', [$questionId, (int) $tagId]);
        }
    }

    /**
     * Excel の1セル(改行区切りの「親」か「親 > 子」)をタグの id にする。知らない名前は例外。
     * 同じ表記のタグが自組織と共有の両方にあれば、テナントの設問では自組織のタグを使う。
     * @param list<array<string,mixed>> $visible visible() の結果(行ごとに引き直さないため呼ぶ側で1回だけ作る)
     * @return list<int>
     * @throws InvalidArgumentException
     */
    public static function resolvePaths(string $cell, ?int $questionTenant, array $visible): array
    {
        $ids = [];
        foreach (preg_split('/\R/u', $cell) ?: [] as $line) {
            $key = self::pathKey($line);
            if ($key === '') {
                continue;
            }
            $match = null;
            foreach ($visible as $tag) {
                $owner = $tag['tenant_id'] !== null ? (int) $tag['tenant_id'] : null;
                $allowed = $questionTenant === null ? $owner === null : ($owner === null || $owner === $questionTenant);
                if (!$allowed || self::pathKey((string) $tag['path']) !== $key) {
                    continue;
                }
                if ($match === null || ($match['tenant_id'] === null && $owner !== null)) {
                    $match = $tag;
                }
            }
            if ($match === null) {
                throw new InvalidArgumentException('分野のタグ「' . trim($line) . '」が見つかりません');
            }
            $ids[(int) $match['id']] = (int) $match['id'];
        }
        if (count($ids) > self::MAX_PER_QUESTION) {
            throw new InvalidArgumentException('分野のタグは1問に' . self::MAX_PER_QUESTION . '件までです');
        }
        return array_values($ids);
    }

    /** 共有の設問には共有のタグだけ。テナントの設問には共有か同じテナントのタグ。 */
    public static function assertScope(?int $tagTenant, ?int $questionTenant): void
    {
        if ($questionTenant === null && $tagTenant !== null) {
            throw new InvalidArgumentException('共有の設問には共有のタグだけを付けられます');
        }
        if ($questionTenant !== null && $tagTenant !== null && $tagTenant !== $questionTenant) {
            throw new InvalidArgumentException('分野のタグが見つかりません');
        }
    }

    /** 「親 > 子」の表記を比べるための形(前後と区切りの空白の違いを無視する)。 */
    private static function pathKey(string $path): string
    {
        $parts = array_map(static fn(string $p): string => self::normalizeName($p), explode('>', $path));
        return implode('>', array_filter($parts, static fn(string $p): bool => $p !== ''));
    }

    private static function present(array $row, ?array $parent): array
    {
        return $row + [
            'path' => $parent !== null ? $parent['name'] . self::PATH_SEPARATOR . $row['name'] : (string) $row['name'],
            'parent_name' => $parent['name'] ?? null,
            'is_shared' => $row['tenant_id'] === null ? 1 : 0,
        ];
    }
}
