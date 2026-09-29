-- 段D の送信を伴う機能のうち D4〜D6(報告者への返信、受講期間の終了時の集計通知、訓練後のアンケートの自動配信)。
-- どれも既定で送らない。行がなければ何も送らない(設定の表は「有効」の行があるテナント・キャンペーンだけが対象)。

-- D4(G10): 不審メールの報告者への定型文の返信の記録。担当者が画面で押した時だけ送る(自動では送らない)。
-- 同じ報告に同じ定型文を二重に送らない(送信中か送信済みの行があれば断る)。失敗した行は再送を妨げない。
CREATE TABLE IF NOT EXISTS suspicious_mail_replies (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id          INTEGER REFERENCES tenants(id) ON DELETE CASCADE,
  suspicious_mail_id INTEGER NOT NULL REFERENCES suspicious_mails(id) ON DELETE CASCADE,
  kind               TEXT NOT NULL,                 -- report_reply_training など(NotificationTemplates)
  to_email           TEXT NOT NULL,                 -- 送った時の報告者のアドレス
  subject            TEXT NOT NULL,
  status             TEXT NOT NULL DEFAULT 'sending' CHECK (status IN ('sending','sent','failed')),
  sent_by            TEXT NOT NULL,                 -- 押した担当者のメールアドレス
  created_at         TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  sent_at            TEXT
);
CREATE INDEX IF NOT EXISTS idx_suspicious_mail_replies_mail ON suspicious_mail_replies(suspicious_mail_id, kind, status);

-- D5(G63): 受講期間の終了時の集計通知の設定(テナントごと)。行がないか enabled=0 なら送らない。
-- enabled_at より前に期限を過ぎた配信は送らない(有効にした瞬間に過去の配信の分がまとめて届くのを防ぐ)。
CREATE TABLE IF NOT EXISTS edu_summary_settings (
  tenant_id  INTEGER PRIMARY KEY REFERENCES tenants(id) ON DELETE CASCADE,
  enabled    INTEGER NOT NULL DEFAULT 0,
  recipients TEXT NOT NULL DEFAULT '[]',            -- JSON 配列(社内の担当者のメールアドレス、10件まで)
  enabled_at TEXT,
  updated_by TEXT,
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- D5: 配信ごとの集計通知の台帳。配信1件につき1行(送る前に行を作って二重送信を防ぐ)。
CREATE TABLE IF NOT EXISTS edu_delivery_summaries (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  delivery_id INTEGER NOT NULL UNIQUE REFERENCES edu_deliveries(id) ON DELETE CASCADE,
  status      TEXT NOT NULL DEFAULT 'sending' CHECK (status IN ('sending','sent','failed')),
  recipients  INTEGER NOT NULL DEFAULT 0,
  sent        INTEGER NOT NULL DEFAULT 0,
  counts      TEXT,                                 -- JSON(人数と率だけ。個人は含めない)
  created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  finished_at TEXT
);

-- D6(G64): 訓練を閉じた時のアンケートの自動配信の設定(キャンペーンごと)。行がないか enabled=0 なら配らない。
-- processed_at は閉じた時に1回だけ処理したことの印(再処理しない)。
CREATE TABLE IF NOT EXISTS campaign_survey_followups (
  campaign_id   INTEGER PRIMARY KEY REFERENCES campaigns(id) ON DELETE CASCADE,
  tenant_id     INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  enabled       INTEGER NOT NULL DEFAULT 0,
  survey_id     INTEGER REFERENCES surveys(id) ON DELETE SET NULL,
  audience      TEXT NOT NULL DEFAULT 'failed' CHECK (audience IN ('failed','all')),
  deadline_days INTEGER,                            -- 回答の締切(閉じた日からの日数)。NULL は期限なし
  delivery_id   INTEGER REFERENCES survey_deliveries(id) ON DELETE SET NULL,
  processed_at  TEXT,
  result        TEXT,                               -- 処理の結果(画面に出す短い文)
  updated_by    TEXT,
  updated_at    TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
