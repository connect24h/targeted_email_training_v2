<?php
/**
 * 教育の受講の回(L5 再受講)。
 *
 * 表の関係:
 *   - edu_assignments(配信×対象者に1件)と edu_responses(割当に1件)は「最新の提出の回」の結果を持つ。
 *     レポート(教育レポート、支援優先度、訓練×教育)はこの2つを数えるので、集計は最新の提出の回になる。
 *   - edu_attempts は提出のたびに1行(前の回を消さない)。completed_at が NULL の行は受講中の回(割当に高々1行)。
 *   - 完了の後の受け直し(マイページの「もう一度受講する」)は is_retake=1 の回を開く。提出するまでは
 *     割当は完了のままで、レポートも前の回の結果のまま。提出すると、その回の結果で割当と edu_responses を上書きする
 *     (eラーニングで合格点に届かなければ、割当は受講中に戻る=最新の回を使う)。
 *   - 受講のリンク(take.php?token=)はそのまま使う。トークンは割当ごとで、回ごとには変えない。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

final class EduAttempts
{
    public const MSG_NOT_ALLOWED = 'この配信は、完了した後の受け直しを受け付けていません';
    public const MSG_EXPIRED = 'この配信は受講の期限が過ぎているため、受け直せません';
    public const MSG_CLOSED = 'この配信は終了しているため、受け直せません';

    /** 受講中の回(提出前)。なければ null。 */
    public static function open(int $assignmentId): ?array
    {
        return Db::one(
            'SELECT * FROM edu_attempts WHERE assignment_id = ? AND completed_at IS NULL ORDER BY attempt_no DESC LIMIT 1',
            [$assignmentId]
        );
    }

    /** 完了の後に受け直している最中か(割当は完了のまま、受け直しの回が開いている)。 */
    public static function retakeOpen(array $assignment): bool
    {
        if ((string) $assignment['status'] !== 'completed') {
            return false;
        }
        $open = self::open((int) $assignment['id']);
        return $open !== null && (int) $open['is_retake'] === 1;
    }

    /** 受講中の回を返す。なければ次の番号で開く(受講の開始と、最初の答え合わせで呼ぶ)。 */
    public static function ensureOpen(array $assignment, bool $isRetake = false): array
    {
        $assignmentId = (int) $assignment['id'];
        return self::atomically(static function () use ($assignment, $assignmentId, $isRetake): array {
            $open = self::open($assignmentId);
            if ($open !== null) {
                return $open;
            }
            $next = (int) (Db::one('SELECT MAX(attempt_no) AS n FROM edu_attempts WHERE assignment_id = ?', [$assignmentId])['n'] ?? 0) + 1;
            Db::run(
                "INSERT INTO edu_attempts (tenant_id, assignment_id, attempt_no, is_retake, started_at)
                 VALUES (?, ?, ?, ?, datetime('now','localtime'))",
                [(int) $assignment['tenant_id'], $assignmentId, $next, $isRetake ? 1 : 0]
            );
            return self::open($assignmentId) ?? throw new RuntimeException('受講の回を作れませんでした');
        });
    }

    /** 受講中の回で確認テストを始めた日時を残す(回がなければ開く。既に残っていれば変えない)。 */
    public static function markTestStarted(array $assignment): void
    {
        $open = self::ensureOpen($assignment);
        Db::run("UPDATE edu_attempts SET test_started_at = datetime('now','localtime') WHERE id = ? AND test_started_at IS NULL",
            [(int) $open['id']]);
    }

    /**
     * 期限を過ぎて受講できないか。期限の後の受講を認める配信(allow_after_deadline=1)は受講できる。
     * $row は割当の token_expiry と、配信の deadline、allow_after_deadline を持つ。
     */
    public static function closedByDeadline(array $row): bool
    {
        if ((int) ($row['allow_after_deadline'] ?? 0) === 1) {
            return false;
        }
        return self::isExpired($row['token_expiry'] ?? null) || self::isExpired($row['deadline'] ?? null);
    }

    /**
     * 提出の結果で受講中の回を閉じる(呼ぶ側のトランザクションの中で使う)。回がなければ作ってから閉じる。
     * @param array{total_score:int,max_score:int,percentage:int,answers:list<array<string,mixed>>} $result
     */
    public static function closeWithResult(array $assignment, array $result, ?bool $passed): int
    {
        $assignmentId = (int) $assignment['id'];
        $open = self::open($assignmentId);
        if ($open === null) {
            $next = (int) (Db::one('SELECT MAX(attempt_no) AS n FROM edu_attempts WHERE assignment_id = ?', [$assignmentId])['n'] ?? 0) + 1;
            $id = Db::insert(
                'INSERT INTO edu_attempts (tenant_id, assignment_id, attempt_no, is_retake, started_at) VALUES (?, ?, ?, 0, ?)',
                [(int) $assignment['tenant_id'], $assignmentId, $next, $assignment['started_at'] ?? null]
            );
        } else {
            $id = (int) $open['id'];
        }
        $answers = [];
        foreach ($result['answers'] as $a) {
            // 配信に含まれない設問(take_score が max_score=0 で返す)は残さない
            if (($a['max_score'] ?? null) === 0) {
                continue;
            }
            $answers[] = [
                'question_id' => (int) $a['question_id'],
                'answer' => array_values(array_map('intval', (array) $a['answer'])),
                'is_correct' => (bool) $a['is_correct'],
                'score_earned' => (int) $a['score_earned'],
            ];
        }
        Db::run(
            "UPDATE edu_attempts SET completed_at = datetime('now','localtime'), total_score = ?, max_score = ?, percentage = ?,
                    passed = ?, answers = ?
             WHERE id = ?",
            [$result['total_score'], $result['max_score'], $result['percentage'],
                $passed === null ? null : ($passed ? 1 : 0), json_encode($answers), $id]
        );
        return $id;
    }

    /** 書き込みの鍵を取って実行する。既にトランザクションの中なら、その中でそのまま実行する(Db は入れ子にできない)。 */
    private static function atomically(callable $fn): mixed
    {
        return Db::pdo()->inTransaction() ? $fn() : Db::txImmediate($fn);
    }

    /** @return list<array<string,mixed>> 割当の回(古い順) */
    public static function forAssignment(int $assignmentId): array
    {
        return Db::all('SELECT * FROM edu_attempts WHERE assignment_id = ? ORDER BY attempt_no', [$assignmentId]);
    }

    /** 提出した回の数(受講回数)。 */
    public static function submittedCount(int $assignmentId): int
    {
        return (int) (Db::one('SELECT COUNT(*) AS n FROM edu_attempts WHERE assignment_id = ? AND completed_at IS NOT NULL',
            [$assignmentId])['n'] ?? 0);
    }

    /** 受講のリンクの期限('YYYY-MM-DD HH:MM:SS')が過ぎたか。空・解釈できない値は期限なし(edu_take.php と同じ)。 */
    public static function isExpired(?string $expiry, ?int $now = null): bool
    {
        if ($expiry === null || trim($expiry) === '') {
            return false;
        }
        $ts = strtotime(preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($expiry)) === 1 ? trim($expiry) . ' 23:59:59' : $expiry);
        return $ts !== false && $ts < ($now ?? time());
    }

    /**
     * 完了の後に受け直せない理由。受け直せるなら null。
     * $row は割当の列に、配信の status AS delivery_status、deadline、allow_retake_after_pass を足したもの。
     */
    public static function retakeBlockReason(array $row): ?string
    {
        if ((string) $row['status'] !== 'completed') {
            return null; // 完了前は受け直しではなく、今の受講を続ける
        }
        if ((int) ($row['allow_retake_after_pass'] ?? 1) !== 1) {
            return self::MSG_NOT_ALLOWED;
        }
        if ((string) ($row['delivery_status'] ?? '') !== 'running') {
            return self::MSG_CLOSED;
        }
        if (self::closedByDeadline($row)) {
            return self::MSG_EXPIRED;
        }
        return null;
    }

    /**
     * 完了した割当に受け直しの回を開く(答え合わせの固定を消し、前の回の結果は残す)。既に開いていればその回を返す。
     * @throws DomainException 受け直せない時(理由の文)
     */
    public static function startRetake(array $row): array
    {
        $reason = self::retakeBlockReason($row);
        if ($reason !== null) {
            throw new DomainException($reason);
        }
        if ((string) $row['status'] !== 'completed') {
            throw new DomainException('この配信はまだ完了していません。受講を続けてください');
        }
        return self::atomically(static function () use ($row): array {
            $open = self::open((int) $row['id']);
            if ($open !== null) {
                return $open;
            }
            // 前の回の1問ごとの答え合わせの固定を消す(前の回の解答は edu_attempts に残っている)
            Db::run('DELETE FROM edu_answer_locks WHERE assignment_id = ?', [(int) $row['id']]);
            return self::ensureOpen($row, true);
        });
    }
}
