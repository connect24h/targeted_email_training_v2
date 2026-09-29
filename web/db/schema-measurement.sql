-- 測定の正しさ(段B1、migration 20261025-measurement-b1)。
-- events の判定の列と campaign_targets の送達の列は schema.sql と MigrationRunner の ensureAdditiveColumns にある。

-- 訓練の送信元の Maildir に届いたメールの取込の記録(web/lib/ReplyIngest.php)。同じメールを2回数えないための台帳。
-- status: reply = 返信として events に入れた / dsn = 戻りメールで宛先を届かないにした / unmatched = 訓練メールへの返信と決められない
--         ignored_auto = 自動の応答(不在の通知など) / from_mismatch = 差出人が宛先の人と違う
-- 突き合わせられないメールは本文を持たず、今の superadmin の返信者の一覧(Maildir を直接読む)で見る。
CREATE TABLE IF NOT EXISTS reply_mails (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  message_id_hash TEXT NOT NULL UNIQUE,
  maildir_file    TEXT NOT NULL,
  received_at     TEXT NOT NULL,
  from_email      TEXT,
  tracking_id     TEXT,
  tenant_id       INTEGER,
  campaign_id     INTEGER,
  status          TEXT NOT NULL,
  event_id        INTEGER,
  created_at      TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_reply_mails_tenant ON reply_mails(tenant_id, campaign_id);
CREATE INDEX IF NOT EXISTS idx_reply_mails_file ON reply_mails(maildir_file);
CREATE INDEX IF NOT EXISTS idx_ct_delivery_state ON campaign_targets(delivery_state);
