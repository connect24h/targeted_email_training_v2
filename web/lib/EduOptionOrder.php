<?php
/**
 * 確認テストの選択肢の表示順(段A の G52)。配信の shuffle_options=1 のときだけ並べ替える。
 *
 * - 並べ替えはサーバーで決める。割当(受講のトークン)と設問ごとに決まった順なので、同じ人が開き直しても同じ順になる。
 *   トークンを種に使うので、ほかの人の順は推測できない。
 * - 受講者の画面は表示の順の番号で解答を送り、サーバーは元の選択肢の番号に戻してから採点して保存する
 *   (edu_answer_locks、edu_response_answers、edu_attempts の解答は常に元の番号。マイページの答え合わせは元の順で見せる)。
 * - 答え合わせ(正解、自分の解答、選択肢ごとの解説)は、受講の画面へ返すときだけ表示の順に直す。
 *
 * 順は「表示の位置 → 元の番号」の配列で表す。例 [2, 0, 1] は、表示の1番目が元の3番目。
 */
declare(strict_types=1);

final class EduOptionOrder
{
    /** 並べ替えるか(配信の shuffle_options)。 */
    public static function enabled(array $assignment): bool
    {
        return (int) ($assignment['shuffle_options'] ?? 0) === 1;
    }

    /**
     * 割当と設問に決まった順。並べ替えない配信は元の順。
     * @return list<int> 表示の位置 → 元の番号
     */
    public static function permutation(array $assignment, int $questionId, int $count): array
    {
        $order = range(0, max(0, $count - 1));
        if ($count < 2 || !self::enabled($assignment)) {
            return $count < 1 ? [] : $order;
        }
        $seed = (string) $assignment['access_token'] . ':' . $questionId . ':';
        $keys = [];
        foreach ($order as $i) {
            $keys[$i] = hash('sha256', $seed . $i);
        }
        usort($order, static fn(int $a, int $b): int => strcmp($keys[$a], $keys[$b]));
        return $order;
    }

    /**
     * 表示の順の番号を元の番号に戻す。範囲の外の番号はそのまま(どの正解にも当たらない)。
     * @param list<int> $order
     * @param list<int> $displayed
     * @return list<int>
     */
    public static function toOriginal(array $order, array $displayed): array
    {
        return array_values(array_map(static fn(int $i): int => $order[$i] ?? $i, $displayed));
    }

    /**
     * 元の番号を表示の順の番号にする。
     * @param list<int> $order
     * @param list<int> $original
     * @return list<int>
     */
    public static function toDisplayed(array $order, array $original): array
    {
        $position = array_flip($order);
        return array_values(array_map(static fn(int $i): int => $position[$i] ?? $i, $original));
    }

    /**
     * 元の順の並び(選択肢の文、選択肢ごとの解説)を表示の順に並べる。長さは選択肢の数(解説が足りない所は空の文)。
     * @param list<int> $order
     */
    public static function arrange(array $order, array $items): array
    {
        $items = array_values($items);
        return array_map(static fn(int $i) => $items[$i] ?? '', $order);
    }
}
