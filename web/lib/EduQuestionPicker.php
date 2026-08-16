<?php
/**
 * 教育配信の出題設問を確定する。
 *
 * この規則はもともと api/edu_deliveries.php と lib/EduAutoEnroll.php に二重で書かれており、
 * 後者は共有設問(tenant_id IS NULL)を拾わない形にコピー劣化していた。本番の設問は全て共有
 * なので、自動連携経路だけが常に出題0件になっていた。同じ事故を繰り返さないよう、
 * 出題規則はこのクラスだけに置き、両方から呼ぶ。
 *
 * 出題対象は「自テナントの設問」と「共有設問(tenant_id IS NULL)」の両方。
 * 同条件ならテナント固有を優先する(自社向けに作った設問が共有版に埋もれないように)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class EduQuestionPicker
{
    /**
     * 配信条件から出題設問IDを決定する。
     *
     * @param array $delivery edu_deliveries の1行(category_ids/difficulty_range/question_count/randomize を参照)
     * @param int   $tenantId 配信先テナント
     * @return list<int> question_id の配列(sort 順)
     */
    public static function pick(array $delivery, int $tenantId): array
    {
        $categoryIds = isset($delivery['category_ids']) && $delivery['category_ids'] !== null
            ? (json_decode((string) $delivery['category_ids'], true) ?: [])
            : [];
        $diffRange = isset($delivery['difficulty_range']) && $delivery['difficulty_range'] !== null
            ? (json_decode((string) $delivery['difficulty_range'], true) ?: [])
            : [];
        $count = isset($delivery['question_count']) && $delivery['question_count'] !== null
            ? (int) $delivery['question_count']
            : 0;
        $randomize = isset($delivery['randomize']) && (int) $delivery['randomize'] === 1;

        // 共有設問(tenant_id NULL) + 自テナントの設問を出題対象にする
        $where  = ['(tenant_id = ? OR tenant_id IS NULL)', 'is_active = 1'];
        $params = [$tenantId];
        if (is_array($categoryIds) && $categoryIds !== []) {
            $ph = implode(',', array_fill(0, count($categoryIds), '?'));
            $where[] = "category_id IN ($ph)";
            foreach ($categoryIds as $cid) {
                $params[] = (int) $cid;
            }
        }
        if (is_array($diffRange) && count($diffRange) === 2) {
            $where[] = 'difficulty BETWEEN ? AND ?';
            $params[] = (int) $diffRange[0];
            $params[] = (int) $diffRange[1];
        }

        // テナント固有(tenant_id IS NULL = 0)を先に。その中での並びは randomize 次第。
        $order = '(tenant_id IS NULL) ASC, ' . ($randomize ? 'RANDOM()' : 'id');
        $sql = 'SELECT id FROM edu_questions WHERE ' . implode(' AND ', $where) . " ORDER BY $order";
        if ($count > 0) {
            $sql .= ' LIMIT ' . $count;
        }

        $ids = [];
        foreach (Db::all($sql, $params) as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }
}
