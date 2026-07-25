-- TET v2 教育(セキュリティアウェアネス)統合 スキーマ (SQLite) — edu_* 8テーブル
-- 接続時に PRAGMA journal_mode=WAL / busy_timeout=5000 / foreign_keys=ON を設定すること。
-- テナント分離: 業務テーブルに tenant_id。結合表(edu_delivery_questions / edu_response_answers)は
--   親(delivery / response)経由でテナント担保するため tenant_id を持たない(既存 campaign_targets / target_group と同じ判断)。
-- 既存 schema.sql の流儀に準拠: IF NOT EXISTS / AUTOINCREMENT / datetime('now','localtime') / FK / idx_<table>_tenant。

-- 教材カテゴリ(IPA10大脅威準拠12カテゴリ)。テナントごとに複製投入(教材は編集される想定のため共有しない)
CREATE TABLE IF NOT EXISTS edu_categories (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL,
  name       TEXT NOT NULL,
  slug       TEXT NOT NULL,
  color      TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  is_active  INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  UNIQUE (tenant_id, slug),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_categories_tenant ON edu_categories(tenant_id);

-- 設問バンク
CREATE TABLE IF NOT EXISTS edu_questions (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id      INTEGER NOT NULL,
  category_id    INTEGER NOT NULL,
  title          TEXT NOT NULL,
  question_type  TEXT NOT NULL DEFAULT 'single_choice', -- single_choice / true_false / multiple_choice
  options        TEXT NOT NULL,                          -- JSON配列 例 ["選択肢A","選択肢B",...]
  correct_answer TEXT NOT NULL,                          -- JSON配列(0始まりindex) 例 [2] / [0,3]
  explanation    TEXT,
  difficulty     INTEGER NOT NULL DEFAULT 1,             -- 配点(移植元 scoring.ts: 難易度=配点)
  is_active      INTEGER NOT NULL DEFAULT 1,
  created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id)   REFERENCES tenants(id),
  FOREIGN KEY (category_id) REFERENCES edu_categories(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_edu_questions_tenant   ON edu_questions(tenant_id);
CREATE INDEX IF NOT EXISTS idx_edu_questions_category ON edu_questions(category_id);

-- 教育配信(eラーニング=合格制約あり / awareness_quiz=継続型・合格制約なし。LRM二層モデル準拠)
CREATE TABLE IF NOT EXISTS edu_deliveries (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id        INTEGER NOT NULL,
  title            TEXT NOT NULL,
  status           TEXT NOT NULL DEFAULT 'draft',       -- draft/scheduled/running/done/cancelled
  delivery_type    TEXT NOT NULL DEFAULT 'elearning',   -- elearning / awareness_quiz
  question_count   INTEGER,
  category_ids     TEXT,                                -- JSON配列
  difficulty_range TEXT,                                -- JSON配列 例 [1,3]
  randomize        INTEGER NOT NULL DEFAULT 1,
  scheduled_at     TEXT,
  deadline         TEXT,
  pass_score       INTEGER,                             -- elearning のみ必達
  target_type      TEXT,                                -- all / group / risk
  target_group_id  INTEGER,
  triggered_by     TEXT,                                -- manual / phishing_failure / new_target
  phish_campaign_id INTEGER,
  created_by       INTEGER,
  created_at       TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id)         REFERENCES tenants(id),
  FOREIGN KEY (target_group_id)   REFERENCES groups(id),
  FOREIGN KEY (phish_campaign_id) REFERENCES campaigns(id),
  FOREIGN KEY (created_by)        REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_deliveries_tenant ON edu_deliveries(tenant_id, status);

-- 配信-設問 結合表(delivery 経由でテナント担保。tenant_id なし)
CREATE TABLE IF NOT EXISTS edu_delivery_questions (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  delivery_id INTEGER NOT NULL,
  question_id INTEGER NOT NULL,
  sort_order  INTEGER NOT NULL DEFAULT 0,
  UNIQUE (delivery_id, question_id),
  FOREIGN KEY (delivery_id) REFERENCES edu_deliveries(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES edu_questions(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_dq_delivery ON edu_delivery_questions(delivery_id);

-- 受講割当(受講者UIはログイン不要のトークン方式。既存 tracking_id 流儀を長めトークンで)
CREATE TABLE IF NOT EXISTS edu_assignments (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL,
  delivery_id  INTEGER NOT NULL,
  target_id    INTEGER NOT NULL,
  access_token TEXT NOT NULL UNIQUE,
  token_expiry TEXT,
  status       TEXT NOT NULL DEFAULT 'assigned',        -- assigned/started/completed/expired
  started_at   TEXT,
  completed_at TEXT,
  score        INTEGER,
  created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  UNIQUE (delivery_id, target_id),
  FOREIGN KEY (tenant_id)   REFERENCES tenants(id),
  FOREIGN KEY (delivery_id) REFERENCES edu_deliveries(id) ON DELETE CASCADE,
  FOREIGN KEY (target_id)   REFERENCES targets(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_assignments_tenant ON edu_assignments(tenant_id);
CREATE INDEX IF NOT EXISTS idx_edu_assignments_token  ON edu_assignments(access_token);
CREATE INDEX IF NOT EXISTS idx_edu_assignments_delivery ON edu_assignments(delivery_id);

-- 受講結果(assignment 1件に対し1件)
CREATE TABLE IF NOT EXISTS edu_responses (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  assignment_id INTEGER NOT NULL UNIQUE,
  total_score   INTEGER,
  max_score     INTEGER,
  percentage    INTEGER,
  started_at    TEXT,
  completed_at  TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  FOREIGN KEY (assignment_id) REFERENCES edu_assignments(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_edu_responses_tenant ON edu_responses(tenant_id);

-- 設問別回答(response 経由でテナント担保。tenant_id なし)
CREATE TABLE IF NOT EXISTS edu_response_answers (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  response_id  INTEGER NOT NULL,
  question_id  INTEGER NOT NULL,
  answer       TEXT,                                    -- JSON配列(選択index)
  is_correct   INTEGER,
  score_earned INTEGER,
  FOREIGN KEY (response_id) REFERENCES edu_responses(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES edu_questions(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_ra_response ON edu_response_answers(response_id);

-- スコアスナップショット(経年トレンド用)
CREATE TABLE IF NOT EXISTS edu_score_snapshots (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NOT NULL,
  snapshot_type   TEXT NOT NULL,                        -- individual / group / company
  target_id       INTEGER,
  group_id        INTEGER,
  average_score   REAL,
  category_scores TEXT,                                 -- JSON
  respondent_count INTEGER,
  snapshot_date   TEXT NOT NULL,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  FOREIGN KEY (target_id) REFERENCES targets(id),
  FOREIGN KEY (group_id)  REFERENCES groups(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_score_snapshots_tenant ON edu_score_snapshots(tenant_id, snapshot_type, snapshot_date);
