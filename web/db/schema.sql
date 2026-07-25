-- TET v2 スキーマ (SQLite)
-- 接続時に PRAGMA journal_mode=WAL / busy_timeout=5000 / foreign_keys=ON を設定すること。
-- テナント分離: 全業務テーブルに tenant_id。リポジトリ層で WHERE tenant_id=? を機械付与。

-- テナント（組織）
CREATE TABLE IF NOT EXISTS tenants (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  slug       TEXT NOT NULL UNIQUE,              -- 英数字・ハイフンのみ。data_dir 名に使用
  data_dir   TEXT NOT NULL,                     -- /opt/training/tet2-data/{slug}
  status     TEXT NOT NULL DEFAULT 'active',    -- active / suspended
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 管理者ユーザ（訓練を運用する側）。tenant_id NULL = superadmin（全テナント）
CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER,
  email         TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,                  -- password_hash(PASSWORD_DEFAULT)
  name          TEXT,
  role          TEXT NOT NULL,                  -- superadmin / tenant_admin / operator / viewer
  status        TEXT NOT NULL DEFAULT 'active',
  failed_count  INTEGER NOT NULL DEFAULT 0,
  locked_until  TEXT,
  last_login_at TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
CREATE INDEX IF NOT EXISTS idx_users_tenant ON users(tenant_id);

-- 訓練対象者（メール受信者）
CREATE TABLE IF NOT EXISTS targets (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL,
  email      TEXT NOT NULL,
  name       TEXT,
  company    TEXT,
  department TEXT,
  title      TEXT,
  status     TEXT NOT NULL DEFAULT 'active',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  UNIQUE (tenant_id, email),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
CREATE INDEX IF NOT EXISTS idx_targets_tenant ON targets(tenant_id);

-- グループ（部署 / 任意グループ）
CREATE TABLE IF NOT EXISTS groups (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id INTEGER NOT NULL,
  name      TEXT NOT NULL,
  kind      TEXT NOT NULL DEFAULT 'custom',     -- department / custom
  UNIQUE (tenant_id, name),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);

CREATE TABLE IF NOT EXISTS target_group (
  target_id INTEGER NOT NULL,
  group_id  INTEGER NOT NULL,
  PRIMARY KEY (target_id, group_id),
  FOREIGN KEY (target_id) REFERENCES targets(id) ON DELETE CASCADE,
  FOREIGN KEY (group_id)  REFERENCES groups(id)  ON DELETE CASCADE
);

-- テンプレート（件名 / 本文 / 偽ログイン / ネタバラシ / eラーニング）
-- tenant_id NULL = 共有プリセット（セキュリオの40種相当）
CREATE TABLE IF NOT EXISTS templates (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER,
  kind       TEXT NOT NULL,                     -- subject / body / phish_login / debrief / elearning
  name       TEXT NOT NULL,
  lang       TEXT NOT NULL DEFAULT 'ja',
  format     TEXT NOT NULL DEFAULT 'html',      -- html / text
  content    TEXT NOT NULL,                     -- プレースホルダ #$1$#..#$6$#
  auth_flag  INTEGER,                           -- phish_login のみ: 0=通常 1=Box 2=MS365 3=DigitalArts
  is_preset  INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
CREATE INDEX IF NOT EXISTS idx_templates_kind ON templates(kind, tenant_id);

-- キャンペーン（訓練）
CREATE TABLE IF NOT EXISTS campaigns (
  id                  INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id           INTEGER NOT NULL,
  name                TEXT NOT NULL,
  status              TEXT NOT NULL DEFAULT 'draft', -- draft/scheduled/running/paused/done/cancelled
  subject_template_id INTEGER,
  body_template_id    INTEGER,
  phish_template_id   INTEGER,
  from_address        TEXT,
  from_domain         TEXT,
  link_mode           TEXT,                      -- link / attachment / form
  attachment_ext      TEXT,
  attachment_zip      INTEGER NOT NULL DEFAULT 0,
  send_mode           TEXT,                      -- normal / split / slow
  split_count         INTEGER,
  split_interval_min  INTEGER,                   -- 5 / 15 / 30 / 60
  weekdays_only       INTEGER NOT NULL DEFAULT 1,
  business_start      TEXT,                      -- '09:00'
  business_end        TEXT,                      -- '18:00'
  start_at            TEXT,
  end_at              TEXT,
  is_test             INTEGER NOT NULL DEFAULT 0,
  data_dir            TEXT,
  created_by          INTEGER,
  created_at          TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id)  REFERENCES tenants(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_campaigns_tenant ON campaigns(tenant_id, status);

-- キャンペーン対象（対象者スナップショット + tracking_id 採番）
CREATE TABLE IF NOT EXISTS campaign_targets (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_id     INTEGER NOT NULL,
  target_id       INTEGER NOT NULL,
  tracking_id     TEXT NOT NULL UNIQUE,          -- 10桁乱数 = list.csv 乱数列。全体で一意
  koban           INTEGER,                       -- list.csv 項番
  auth_flag       INTEGER,
  from_address    TEXT,
  attachment_path TEXT,
  send_status     TEXT NOT NULL DEFAULT 'pending', -- pending/sent/failed/deferred
  sent_at         TEXT,
  UNIQUE (campaign_id, target_id),
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (target_id)   REFERENCES targets(id)
);
CREATE INDEX IF NOT EXISTS idx_ct_campaign ON campaign_targets(campaign_id);

-- 送信スケジュール（ワーカーがポーリング）
CREATE TABLE IF NOT EXISTS send_schedule (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_id  INTEGER NOT NULL,
  batch_no     INTEGER NOT NULL DEFAULT 1,
  scheduled_at TEXT NOT NULL,
  koban_from   INTEGER,
  koban_to     INTEGER,
  interval_sec INTEGER NOT NULL DEFAULT 3,
  status       TEXT NOT NULL DEFAULT 'queued',   -- queued/claimed/running/done/failed/cancelled
  claimed_at   TEXT,
  worker_pid   INTEGER,
  attempts     INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_sched_due ON send_schedule(status, scheduled_at);

-- イベント（開封/クリック/認証/フォーム/返信/報告）。冪等インジェスト
CREATE TABLE IF NOT EXISTS events (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL,
  campaign_id  INTEGER,
  tracking_id  TEXT NOT NULL,
  event_type   TEXT NOT NULL,                    -- open/click/auth/form_submit/reply/report
  auth_variant TEXT,                             -- Box/MS365/DA 等
  occurred_at  TEXT NOT NULL,
  source       TEXT,                             -- apache_access / text_log / manual
  raw          TEXT,
  UNIQUE (tracking_id, event_type, occurred_at)
);
CREATE INDEX IF NOT EXISTS idx_events_campaign ON events(campaign_id, event_type);

-- 配信ログ（SMTP 結果）
CREATE TABLE IF NOT EXISTS delivery_log (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  campaign_id  INTEGER,
  tracking_id  TEXT,
  to_email     TEXT,
  result       TEXT,                             -- sent/deferred/failed
  smtp_message TEXT,
  occurred_at  TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_delivery_campaign ON delivery_log(campaign_id);

-- 監査ログ
CREATE TABLE IF NOT EXISTS audit_log (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER,
  user_id     INTEGER,
  action      TEXT,
  detail      TEXT,
  ip          TEXT,
  occurred_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
