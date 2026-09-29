<?php
/**
 * 不審メールの報告者への定型文の返信(段D の D4、G10)。
 *
 * - 担当者が不審メールの詳細で定型文を選んで押した時だけ送る。自動では送らない。
 * - 宛先はその報告の報告者(suspicious_mails.reporter_email)だけ。宛先を画面や API から指定する口はない。
 * - 「訓練メールでした」は、報告のメールが訓練のキャンペーンに結び付き、そのキャンペーンを閉じた後だけ送れる
 *   (閉じる前に知らせると、訓練の測定が崩れるため)。キャンペーンを特定できない報告には送れない。
 * - 同じ報告に同じ定型文は1回だけ(送信中か送信済みの行があれば断る。二度押しもここで止まる)。失敗した時は送り直せる。
 * - 送った記録は suspicious_mail_replies と、報告の履歴(suspicious_mail_history の field='reply')に残す。
 * - 文面は通知の文面(NotificationTemplates)の report_reply_* で、テナントが上書きできる。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduMailer.php';
require_once __DIR__ . '/NotificationTemplates.php';
require_once __DIR__ . '/SuspiciousMailStore.php';

final class SuspiciousMailReplies
{
    /** 件名の差し込みに使う長さの上限(報告されたメールの件名は外から届いた文字列)。 */
    private const SUBJECT_VAR_MAX = 120;

    /**
     * 返信の画面の内容。定型文ごとに見本(実際に送る件名と本文)、送れるか、送れない理由、送った日時を返す。
     * @return array{reporter_email:?string, items:list<array<string,mixed>>, replies:list<array<string,mixed>>}
     */
    public static function options(int $id, ?int $tenantScope): array
    {
        $mail = self::mail($id, $tenantScope);
        $reporter = self::reporter($mail);
        $items = [];
        foreach (NotificationTemplates::REPORT_REPLY_KINDS as $kind) {
            $rendered = self::render($mail, $kind);
            $blocked = $reporter === null ? '報告者のメールアドレスがないため返信できません' : self::blockReason($mail, $kind);
            $items[] = [
                'kind' => $kind,
                'label' => NotificationTemplates::definition($kind)['label'],
                'subject' => $rendered['subject'],
                'body' => $rendered['body'],
                'allowed' => $blocked === null,
                'reason' => $blocked,
            ];
        }
        return ['reporter_email' => $reporter, 'items' => $items, 'replies' => self::replies($id)];
    }

    /**
     * 選んだ定型文を報告者へ1通送る。
     * @return array{reply_id:int, to:string, kind:string}
     * @throws DomainException 送れない時(code は HTTP の状態)
     */
    public static function send(int $id, ?int $tenantScope, string $kind, string $actorEmail): array
    {
        if (!in_array($kind, NotificationTemplates::REPORT_REPLY_KINDS, true)) {
            throw new DomainException('返信の定型文が正しくありません', 400);
        }
        $mail = self::mail($id, $tenantScope);
        $to = self::reporter($mail);
        if ($to === null) {
            throw new DomainException('報告者のメールアドレスがないため返信できません', 409);
        }
        $reason = self::blockReason($mail, $kind);
        if ($reason !== null) {
            throw new DomainException($reason, 409);
        }
        $mailText = self::render($mail, $kind);
        $replyId = Db::txImmediate(static function () use ($mail, $kind, $to, $mailText, $actorEmail): int {
            // 送信中の返信があれば断る(二度押しと、別の担当者が同時に押した時)
            $busy = Db::one("SELECT kind FROM suspicious_mail_replies WHERE suspicious_mail_id = ? AND (status = 'sending'
                OR (kind = ? AND status = 'sent')) LIMIT 1", [(int) $mail['id'], $kind]);
            if ($busy !== null) {
                throw new DomainException($busy['kind'] === $kind ? 'この定型文はこの報告者へ送ってあります' : 'ほかの返信を送っているところです。少し待ってから開き直してください', 409);
            }
            return Db::insert('INSERT INTO suspicious_mail_replies (tenant_id, suspicious_mail_id, kind, to_email, subject, sent_by)
                VALUES (?, ?, ?, ?, ?, ?)', [$mail['tenant_id'], (int) $mail['id'], $kind, $to, $mailText['subject'], $actorEmail]);
        });
        $ok = EduMailer::send($to, $mailText['subject'], $mailText['body']);
        $now = date('Y-m-d H:i:s');
        Db::tx(static function () use ($replyId, $ok, $now, $mail, $kind, $actorEmail): void {
            Db::run('UPDATE suspicious_mail_replies SET status = ?, sent_at = ? WHERE id = ?', [$ok ? 'sent' : 'failed', $ok ? $now : null, $replyId]);
            Db::run('INSERT INTO suspicious_mail_history (suspicious_mail_id, actor_email, field, old_value, new_value, created_at) VALUES (?,?,?,?,?,?)',
                [(int) $mail['id'], $actorEmail, 'reply', null, NotificationTemplates::definition($kind)['label'] . ($ok ? '' : '（送れませんでした）'), $now]);
        });
        if (!$ok) {
            throw new DomainException('返信を送れませんでした（メールサーバーに接続できないか、宛先が受け付けられませんでした）', 502);
        }
        return ['reply_id' => $replyId, 'to' => $to, 'kind' => $kind];
    }

    /**
     * その定型文を送れない理由(送れるなら null)。「訓練メールでした」だけ、閉じた訓練の報告に限る。
     * @param array<string,mixed> $mail
     */
    public static function blockReason(array $mail, string $kind): ?string
    {
        if ($kind !== 'report_reply_training') {
            return null;
        }
        $campaign = self::trainingCampaign($mail);
        if ($campaign === null) {
            return '訓練のキャンペーンを特定できない報告には「訓練メールでした」を送れません';
        }
        if ($campaign['closed_at'] === null) {
            return '訓練を閉じる前は「訓練メールでした」を送れません（訓練の測定が崩れるため）。訓練のレポートでクローズしてから送ってください';
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function mail(int $id, ?int $tenantScope): array
    {
        $mail = SuspiciousMailStore::find($id, $tenantScope);
        if ($mail === null) {
            throw new DomainException('不審メールが見つかりません', 404);
        }
        return $mail;
    }

    /** @param array<string,mixed> $mail */
    private static function reporter(array $mail): ?string
    {
        $email = trim((string) ($mail['reporter_email'] ?? ''));
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * 報告のメールが結び付く訓練のキャンペーン(追跡 ID から引く。報告のテナントと同じキャンペーンだけ)。
     * @param array<string,mixed> $mail
     */
    private static function trainingCampaign(array $mail): ?array
    {
        $trackingId = trim((string) ($mail['tracking_id'] ?? ''));
        if ($trackingId === '' || $mail['tenant_id'] === null) {
            return null;
        }
        return Db::one('SELECT c.id, c.closed_at FROM campaign_targets ct JOIN campaigns c ON c.id = ct.campaign_id
            WHERE ct.tracking_id = ? AND c.tenant_id = ? AND c.deleted_at IS NULL', [$trackingId, (int) $mail['tenant_id']]);
    }

    /**
     * @param array<string,mixed> $mail
     * @return array{subject:string, body:string}
     */
    private static function render(array $mail, string $kind): array
    {
        $tenantId = $mail['tenant_id'] !== null ? (int) $mail['tenant_id'] : null;
        $name = '';
        $reporter = self::reporter($mail);
        if ($tenantId !== null && $reporter !== null) {
            $name = (string) (Db::one("SELECT name FROM targets WHERE tenant_id = ? AND email = ? COLLATE NOCASE AND status = 'active' LIMIT 1",
                [$tenantId, $reporter])['name'] ?? '');
        }
        return NotificationTemplates::render($tenantId, $kind, [
            '氏名' => $name,
            '件名' => mb_substr(str_replace(["\r", "\n"], ' ', (string) ($mail['subject'] ?? '')), 0, self::SUBJECT_VAR_MAX),
            '報告日時' => substr((string) $mail['received_at'], 0, 16),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private static function replies(int $id): array
    {
        return Db::all('SELECT id, kind, to_email, subject, status, sent_by, created_at, sent_at FROM suspicious_mail_replies
            WHERE suspicious_mail_id = ? ORDER BY id DESC', [$id]);
    }
}
