-- 段D の送信(D-a)。種明かしメール(D2、G06)、担当者への報告の通知(D3、G38)、送った記録。
-- 催促の設定(D1、G34)は edu_deliveries と edu_assignments の列(MigrationRunner の ensureAdditiveColumns)。
-- どの送信も既定で切(行がない、または 0)。行がなければ1通も送らない。

-- 訓練ごとの種明かしメールの設定。行がないキャンペーンは3つの条件がすべて切。
CREATE TABLE IF NOT EXISTS campaign_reveal_settings (
  campaign_id  INTEGER PRIMARY KEY REFERENCES campaigns(id) ON DELETE CASCADE,
  tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  on_fail      INTEGER NOT NULL DEFAULT 0,        -- (a) 防衛に失敗した(クリック・入力)直後に、その本人へだけ送る
  fail_since   TEXT,                              -- (a) を入れた日時。これより前の失敗には送らない
  on_close     INTEGER NOT NULL DEFAULT 0,        -- (b) 訓練を閉じた(closed_at)後に送る
  close_scope  TEXT NOT NULL DEFAULT 'all' CHECK (close_scope IN ('all','failed','not_failed')),
  on_report    INTEGER NOT NULL DEFAULT 0,        -- (c) 訓練メールを報告した人へ送る(訓練を閉じた後)
  updated_by   TEXT,
  updated_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- テナントの不審メールの報告の通知先。行がないか emails が空なら通知しない。
CREATE TABLE IF NOT EXISTS tenant_report_notify (
  tenant_id    INTEGER PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
  emails       TEXT NOT NULL DEFAULT '',          -- 改行で区切ったアドレス(10件まで)
  since        TEXT,                              -- 通知先を入れた日時。これより前に取り込んだ報告には送らない
  updated_by   TEXT,
  updated_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 自動の通知を送った記録。(テナント、種類、重複の鍵)で1行なので、同じ人へ同じ条件で2通は送らない。
-- 送る前に status='sending' で行を取ってから送る(同時に動いても二重に送らない)。途中で止まった行は送り直さない。
CREATE TABLE IF NOT EXISTS notification_sends (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  kind         TEXT NOT NULL,                     -- reveal_failed / reveal_closed / reveal_reported / report_notify
  dedupe_key   TEXT NOT NULL,                     -- 例 c12:t34(キャンペーンと対象者)、s56:担当者のアドレス
  campaign_id  INTEGER,
  target_id    INTEGER,
  suspicious_mail_id INTEGER,
  recipient    TEXT NOT NULL,
  token        TEXT UNIQUE,                       -- 種明かしのページを開くトークン(種明かしメールだけ)
  status       TEXT NOT NULL DEFAULT 'sending' CHECK (status IN ('sending','sent','failed')),
  attempts     INTEGER NOT NULL DEFAULT 1,
  created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  sent_at      TEXT,
  UNIQUE (tenant_id, kind, dedupe_key)
);
CREATE INDEX IF NOT EXISTS idx_notification_sends_tenant ON notification_sends(tenant_id, kind, created_at);
