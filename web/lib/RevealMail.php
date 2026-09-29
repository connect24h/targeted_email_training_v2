<?php
/**
 * 種明かしメール(段D の D2、G06)。訓練ごとに3つの条件を選べる(既定はすべて切。設定の行がなければ1通も送らない)。
 *
 *   (a) on_fail   防衛に失敗した(リンクのクリックか入力。verdict='user' だけ)直後に、その本人へだけ送る。
 *                 訓練の実施中でも送る唯一の条件。入れた日時(fail_since)より前の失敗には送らない。
 *   (b) on_close  訓練を閉じた(campaigns.closed_at)後に、送った対象者へ送る。範囲は全員・失敗した人・失敗しなかった人。
 *   (c) on_report 訓練のメールを報告した人へ送る。訓練の実施中に送ると測定が崩れるので、訓練を閉じた後に送る。
 *
 * - 1人に1つの条件で1通(notification_sends の (テナント、種類、キャンペーンと対象者) の1行)。
 * - 送らない訓練: 削除済み、テスト(is_test。宛先がテストの転送先に替わるため)、利用停止のテナント。
 * - 送らない人: 送っていない宛先、届かない宛先(undeliverable)、在籍していない対象者。
 * - 本文は通知の文面(C2)の reveal_failed / reveal_closed / reveal_reported。{種明かしURL} は reveal_view.php の
 *   トークンつきの URL で、訓練の種明かしのページ(キャンペーンで選んだページか、テナントの既定)を出す。
 *   このページは開いても events に何も書かない(訓練の測定に入らない)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduMailer.php';
require_once __DIR__ . '/NotificationTemplates.php';
require_once __DIR__ . '/NotificationSends.php';
require_once __DIR__ . '/RevealPages.php';
require_once __DIR__ . '/TenantStatus.php';

final class RevealMail
{
    public const KIND_FAILED = 'reveal_failed';
    public const KIND_CLOSED = 'reveal_closed';
    public const KIND_REPORTED = 'reveal_reported';
    public const KINDS = [self::KIND_FAILED, self::KIND_CLOSED, self::KIND_REPORTED];
    public const CLOSE_SCOPES = ['all', 'failed', 'not_failed'];

    /** 1回の実行で送る上限(大きな訓練を閉じても、1回で送り切らずに次の実行へ回す)。 */
    public const DEFAULT_RUN_LIMIT = 500;
    /** 訓練を閉じる操作から同期で送る上限(画面の応答を待たせすぎない。残りは CLI が送る)。 */
    public const CLOSE_HOOK_LIMIT = 200;
    /** 種明かしのページのリンクを開ける日数。 */
    public const TOKEN_TTL_DAYS = 180;

    /** @return array{on_fail:int, fail_since:?string, on_close:int, close_scope:string, on_report:int, updated_by:?string, updated_at:?string} */
    public static function settings(int $tenantId, int $campaignId): array
    {
        $row = Db::one('SELECT on_fail, fail_since, on_close, close_scope, on_report, updated_by, updated_at
                        FROM campaign_reveal_settings WHERE campaign_id = ? AND tenant_id = ?', [$campaignId, $tenantId]);
        if ($row === null) {
            return ['on_fail' => 0, 'fail_since' => null, 'on_close' => 0, 'close_scope' => 'all', 'on_report' => 0,
                'updated_by' => null, 'updated_at' => null];
        }
        return ['on_fail' => (int) $row['on_fail'], 'fail_since' => $row['fail_since'], 'on_close' => (int) $row['on_close'],
            'close_scope' => (string) $row['close_scope'], 'on_report' => (int) $row['on_report'],
            'updated_by' => $row['updated_by'], 'updated_at' => $row['updated_at']];
    }

    /**
     * 設定を保存する(呼び出し側でキャンペーンがテナントのものか確かめておく)。
     * (a) を切から入にした時だけ fail_since を今にする(入れる前の失敗へさかのぼって送らない)。
     * @param array{on_fail:bool, on_close:bool, close_scope:string, on_report:bool} $in
     */
    public static function saveSettings(int $tenantId, int $campaignId, array $in, ?string $actor): array
    {
        if (!in_array($in['close_scope'], self::CLOSE_SCOPES, true)) {
            throw new DomainException('送る範囲が正しくありません', 400);
        }
        $before = self::settings($tenantId, $campaignId);
        $onFail = $in['on_fail'] ? 1 : 0;
        $failSince = $onFail === 1 ? ($before['on_fail'] === 1 ? $before['fail_since'] : date('Y-m-d H:i:s')) : null;
        Db::run(
            "INSERT INTO campaign_reveal_settings (campaign_id, tenant_id, on_fail, fail_since, on_close, close_scope, on_report, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'))
             ON CONFLICT (campaign_id) DO UPDATE SET on_fail = excluded.on_fail, fail_since = excluded.fail_since,
                 on_close = excluded.on_close, close_scope = excluded.close_scope, on_report = excluded.on_report,
                 updated_by = excluded.updated_by, updated_at = excluded.updated_at
             WHERE campaign_reveal_settings.tenant_id = excluded.tenant_id",
            [$campaignId, $tenantId, $onFail, $failSince, $in['on_close'] ? 1 : 0, $in['close_scope'], $in['on_report'] ? 1 : 0, $actor]
        );
        return self::settings($tenantId, $campaignId);
    }

    /**
     * 送る(timer の CLI から)。$opts: tenant_id、campaign_id(絞る)、conditions(既定は3つとも)、limit。
     * @return array{reveal_failed:int, reveal_closed:int, reveal_reported:int, failed:int, remaining:int}
     */
    public static function run(array $opts = []): array
    {
        $limit = max(0, (int) ($opts['limit'] ?? self::DEFAULT_RUN_LIMIT));
        $conditions = $opts['conditions'] ?? self::KINDS;
        $result = [self::KIND_FAILED => 0, self::KIND_CLOSED => 0, self::KIND_REPORTED => 0, 'failed' => 0, 'remaining' => 0];
        foreach (self::KINDS as $kind) {
            if (!in_array($kind, $conditions, true)) {
                continue;
            }
            foreach (self::candidates($kind, $opts) as $row) {
                if ($result[self::KIND_FAILED] + $result[self::KIND_CLOSED] + $result[self::KIND_REPORTED] + $result['failed'] >= $limit) {
                    $result['remaining']++;
                    continue;
                }
                $outcome = self::sendOne($kind, $row);
                if ($outcome === true) {
                    $result[$kind]++;
                } elseif ($outcome === false) {
                    $result['failed']++;
                }
            }
        }
        return $result;
    }

    /** 訓練を閉じた直後に、その訓練の (b) と (c) だけを送る(設定が切なら何もしない)。 */
    public static function afterClose(int $tenantId, int $campaignId): array
    {
        return self::run(['tenant_id' => $tenantId, 'campaign_id' => $campaignId,
            'conditions' => [self::KIND_CLOSED, self::KIND_REPORTED], 'limit' => self::CLOSE_HOOK_LIMIT]);
    }

    /**
     * 種明かしのページの HTML(トークンから)。トークンが違う・古い・訓練が削除された時は null。
     */
    public static function pageForToken(string $token): ?string
    {
        if (preg_match('/^[0-9a-f]{48}$/', $token) !== 1) {
            return null;
        }
        $row = Db::one(
            "SELECT n.tenant_id, n.campaign_id, c.reveal_page_id, t.data_dir
             FROM notification_sends n
             JOIN campaigns c ON c.id = n.campaign_id AND c.tenant_id = n.tenant_id AND c.deleted_at IS NULL
             JOIN tenants t ON t.id = n.tenant_id
             WHERE n.token = ? AND n.kind IN ('reveal_failed','reveal_closed','reveal_reported')
               AND n.created_at >= datetime('now','localtime','-" . self::TOKEN_TTL_DAYS . " days')",
            [$token]
        );
        if ($row === null) {
            return null;
        }
        $html = null;
        if ($row['reveal_page_id'] !== null) {
            $html = RevealPages::content((int) $row['reveal_page_id'], (int) $row['tenant_id']);
        }
        $default = rtrim((string) $row['data_dir'], '/') . '/reveal.html';
        if ($html === null && $row['data_dir'] !== '' && is_file($default) && is_readable($default)) {
            $read = @file_get_contents($default);
            $html = $read === false ? null : $read;
        }
        $html ??= self::fallbackPage();
        // 送信の生成で差し替える記号(追跡の番号、宛先のアドレス、入力本文の収集)は空にする(種明かしのページでは使わない)
        return str_replace(['#$5$#', '#$6$#', '#$C$#'], ['', '', '0'], $html);
    }

    /** テナントに種明かしのページがない時の既定のページ。 */
    public static function fallbackPage(): string
    {
        return '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>標的型メール訓練</title><style>body{font-family:sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;line-height:1.8;color:#243746}'
            . 'h1{font-size:1.4rem}li{margin:.3rem 0}</style></head><body>'
            . '<h1>このメールは標的型メール訓練でした</h1>'
            . '<p>情報セキュリティの向上を目的とした訓練のメールです。実際の被害はありません。</p>'
            . '<h2>見分けるポイント</h2><ul>'
            . '<li>差出人のアドレスが、名乗っている会社やサービスのものか確かめる。</li>'
            . '<li>急がせる言葉（至急、本日中、アカウント停止など）に注意する。</li>'
            . '<li>リンクの行き先や添付ファイルを、開く前に確かめる。</li>'
            . '<li>おかしいと思ったら、開かずに社内の報告の窓口へ報告する。</li>'
            . '</ul></body></html>';
    }

    /** 1通を送る。送れたら true、送れなかったら false、ほかが送った(送っている)なら null。 */
    private static function sendOne(string $kind, array $row): ?bool
    {
        $tenantId = (int) $row['tenant_id'];
        $claim = NotificationSends::claim($tenantId, $kind, self::key($row), (string) $row['email'],
            ['campaign_id' => (int) $row['campaign_id'], 'target_id' => (int) $row['target_id'], 'token' => true]);
        if ($claim === null) {
            return null;
        }
        $mail = NotificationTemplates::render($tenantId, $kind, [
            '氏名' => (string) ($row['name'] ?? ''),
            '送信日' => substr((string) ($row['sent_at'] ?? ''), 0, 10),
            '種明かしURL' => self::pageUrl((string) $claim['token']),
        ]);
        $ok = EduMailer::send((string) $row['email'], $mail['subject'], $mail['body']);
        NotificationSends::finish($claim['id'], $ok);
        return $ok;
    }

    public static function pageUrl(string $token): string
    {
        return EduMailer::baseUrl() . '/reveal_view.php?token=' . rawurlencode($token);
    }

    private static function key(array $row): string
    {
        return 'c' . (int) $row['campaign_id'] . ':t' . (int) $row['target_id'];
    }

    /**
     * 条件ごとの送る相手(キャンペーンと対象者の組、送ったものを除く)。
     * @return list<array<string,mixed>>
     */
    private static function candidates(string $kind, array $opts): array
    {
        $where = ['c.deleted_at IS NULL', 'c.is_test = 0', TenantStatus::operationalSql('c.tenant_id'),
            "t.status = 'active'", 'ct.sent_at IS NOT NULL',
            "(ct.delivery_state IS NULL OR ct.delivery_state <> 'undeliverable')",
            "NOT EXISTS (SELECT 1 FROM notification_sends n WHERE n.tenant_id = c.tenant_id AND n.kind = ?
                 AND n.dedupe_key = 'c' || c.id || ':t' || ct.target_id
                 AND NOT (n.status = 'failed' AND n.attempts < " . NotificationSends::MAX_ATTEMPTS . '))'];
        $params = [$kind];
        if (isset($opts['tenant_id'])) {
            $where[] = 'c.tenant_id = ?';
            $params[] = (int) $opts['tenant_id'];
        }
        if (isset($opts['campaign_id'])) {
            $where[] = 'c.id = ?';
            $params[] = (int) $opts['campaign_id'];
        }
        $failCount = "(SELECT COUNT(*) FROM events e JOIN campaign_targets ct2 ON ct2.tracking_id = e.tracking_id
                        WHERE ct2.campaign_id = c.id AND ct2.target_id = ct.target_id AND e.tenant_id = c.tenant_id
                          AND e.event_type IN ('click','auth') AND e.verdict = 'user'" ;
        if ($kind === self::KIND_FAILED) {
            // 失敗は入れた日時より後のものだけ。訓練を閉じる前でも送る(本人だけ)
            $where[] = 's.on_fail = 1';
            $where[] = $failCount . ' AND e.occurred_at >= s.fail_since) > 0';
        } elseif ($kind === self::KIND_CLOSED) {
            $where[] = 's.on_close = 1';
            $where[] = 'c.closed_at IS NOT NULL';
        } else {
            $where[] = 's.on_report = 1';
            $where[] = 'c.closed_at IS NOT NULL';
            $where[] = "EXISTS (SELECT 1 FROM events e JOIN campaign_targets ct3 ON ct3.tracking_id = e.tracking_id
                          WHERE ct3.campaign_id = c.id AND ct3.target_id = ct.target_id AND e.tenant_id = c.tenant_id
                            AND e.event_type = 'report' AND e.verdict = 'user')";
        }
        $rows = Db::all(
            'SELECT c.id AS campaign_id, c.tenant_id, ct.target_id, t.email, t.name, MIN(ct.sent_at) AS sent_at, s.close_scope, '
            . $failCount . ') AS fail_count
             FROM campaign_targets ct
             JOIN campaigns c ON c.id = ct.campaign_id
             JOIN campaign_reveal_settings s ON s.campaign_id = c.id AND s.tenant_id = c.tenant_id
             JOIN targets t ON t.id = ct.target_id AND t.tenant_id = c.tenant_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY c.id, ct.target_id
             ORDER BY c.id, ct.target_id',
            $params
        );
        if ($kind !== self::KIND_CLOSED) {
            return $rows;
        }
        return array_values(array_filter($rows, static function (array $r): bool {
            $failed = (int) $r['fail_count'] > 0;
            return match ((string) $r['close_scope']) {
                'failed' => $failed,
                'not_failed' => !$failed,
                default => true,
            };
        }));
    }
}
