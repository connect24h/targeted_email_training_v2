-- TET v2 教育(セキュリティアウェアネス)統合 スキーマ (SQLite) — edu_* 8テーブル
-- 接続時に PRAGMA journal_mode=WAL / busy_timeout=5000 / foreign_keys=ON を設定すること。
-- テナント分離: 業務テーブルに tenant_id。結合表(edu_delivery_questions / edu_response_answers)は
--   親(delivery / response)経由でテナント担保するため tenant_id を持たない(既存 campaign_targets / target_group と同じ判断)。
-- 既存 schema.sql の流儀に準拠: IF NOT EXISTS / AUTOINCREMENT / datetime('now','localtime') / FK / idx_<table>_tenant。

-- 教材カテゴリ(IPA10大脅威準拠12カテゴリ)。テナントごとに複製投入(教材は編集される想定のため共有しない)
CREATE TABLE IF NOT EXISTS edu_categories (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER,
  name       TEXT NOT NULL,
  slug       TEXT NOT NULL,
  color      TEXT,
  sort_order INTEGER NOT NULL DEFAULT 0,
  is_active  INTEGER NOT NULL DEFAULT 1,
  is_shared  INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_categories_tenant ON edu_categories(tenant_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_edu_cat_shared_slug
  ON edu_categories(slug) WHERE tenant_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_edu_cat_tenant_slug
  ON edu_categories(tenant_id, slug) WHERE tenant_id IS NOT NULL;

-- 設問バンク
CREATE TABLE IF NOT EXISTS edu_questions (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id      INTEGER,
  category_id    INTEGER NOT NULL,
  title          TEXT NOT NULL,
  question_type  TEXT NOT NULL DEFAULT 'single_choice', -- single_choice / true_false / multiple_choice
  options        TEXT NOT NULL,                          -- JSON配列 例 ["選択肢A","選択肢B",...]
  correct_answer TEXT NOT NULL,                          -- JSON配列(0始まりindex) 例 [2] / [0,3]
  explanation    TEXT,
  option_explanations TEXT,                              -- JSON配列(選択肢と同じ順の解説)。NULL なら explanation だけを使う
  image_name     TEXT,                                   -- 設問の画像。edu-media 配下のファイル名(サーバーが決める)
  difficulty     INTEGER NOT NULL DEFAULT 1,             -- 配点(移植元 scoring.ts: 難易度=配点)
  is_active      INTEGER NOT NULL DEFAULT 1,
  is_shared      INTEGER NOT NULL DEFAULT 0,
  created_at     TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id)   REFERENCES tenants(id),
  FOREIGN KEY (category_id) REFERENCES edu_categories(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_edu_questions_tenant   ON edu_questions(tenant_id);
CREATE INDEX IF NOT EXISTS idx_edu_questions_category ON edu_questions(category_id);

-- スライド教材。本文はplain textのJSON配列として保持し、受講画面ではtextContent相当で描画する。
-- 共有教材はtenant_id NULL。テナント独自教材は後からスライドを差し替えられる。
CREATE TABLE IF NOT EXISTS edu_materials (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER,
  title       TEXT NOT NULL,
  description TEXT,
  slides      TEXT NOT NULL,                            -- JSON [{"title":"...","body":"..."}]
  format      TEXT NOT NULL DEFAULT 'text_slides',      -- text_slides(文字のスライド) / page_images(PDF のページ画像)
  page_count  INTEGER NOT NULL DEFAULT 0,               -- page_images のページ数
  source_name TEXT,                                     -- 取り込んだ PDF の元のファイル名
  version     INTEGER NOT NULL DEFAULT 1,               -- 教材の版(G20)。差し替えのたびに +1

  is_active   INTEGER NOT NULL DEFAULT 1,
  is_shared   INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_materials_tenant ON edu_materials(tenant_id, is_active);

-- 教育配信(eラーニング=合格制約あり / awareness_quiz=継続型・合格制約なし)
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
  feedback_mode    TEXT NOT NULL DEFAULT 'after_submit', -- after_submit(提出後にまとめて) / immediate(1問ごとに答え合わせ)
  material_id      INTEGER,
  target_type      TEXT,                                -- all / group / risk / individual / position
  target_group_id  INTEGER,
  triggered_by     TEXT,                                -- manual / phishing_failure / new_target
  phish_campaign_id INTEGER,
  send_invites     INTEGER NOT NULL DEFAULT 0,          -- 1 の配信だけ、開始時と自動の投入時に受講の案内メールを送る
  allow_retake_after_pass INTEGER NOT NULL DEFAULT 1,  -- 1 = 完了(合格)した後もマイページから受け直せる(新しい回として edu_attempts に残す)
  shuffle_options  INTEGER NOT NULL DEFAULT 0,         -- 1 = 確認テストの選択肢を割当と設問ごとに決まった順に並べ替える(既存の配信は 0、新しい配信は作成の API が既定で 1)
  lock_material_during_test INTEGER NOT NULL DEFAULT 0, -- 1 = テストを始めた後は、提出するまで教材を返さない
  allow_after_deadline INTEGER NOT NULL DEFAULT 0,     -- 1 = 期限の後も受講できる(期限の後の完了はレポートで期限後になる)
  retake_from_test INTEGER NOT NULL DEFAULT 0,         -- 1 = 不合格の後の受け直しを、教材を飛ばして確認テストから始める
  remind_start_days INTEGER,                           -- 自動の催促(D1): 期限の何日前から送るか。NULL = 開始から送る(従来)
  remind_interval_days INTEGER,                        -- 自動の催促: 何日ごとに送るか。NULL = 既定(TET2_REMIND_INTERVAL_DAYS か 3日)
  remind_after_deadline INTEGER NOT NULL DEFAULT 0,    -- 1 = 期限の後も送る(remind_max_count の回数まで。期限後の受講を許す配信だけ)
  remind_max_count INTEGER,                            -- 自動の催促の上限の回数。NULL = 上限なし(期限まで)
  series_id        INTEGER REFERENCES edu_delivery_series(id), -- 毎月の配信(schema-edu-delivery.sql)から作った回
  target_positions TEXT,                                -- target_type=position の役職区分(JSON配列)
  risk_results     TEXT,                                -- target_type=risk の訓練の結果の区分(JSON配列)
  new_target_days  INTEGER,                             -- triggered_by=new_target の「登録から N 日以内」
  created_by       INTEGER,
  created_at       TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id)         REFERENCES tenants(id),
  FOREIGN KEY (material_id)       REFERENCES edu_materials(id),
  FOREIGN KEY (target_group_id)   REFERENCES groups(id),
  FOREIGN KEY (phish_campaign_id) REFERENCES campaigns(id),
  FOREIGN KEY (created_by)        REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_deliveries_tenant ON edu_deliveries(tenant_id, status);

-- 個別対象者指定。delivery経由でtenantを担保し、APIでもtarget所有を確認する。
CREATE TABLE IF NOT EXISTS edu_delivery_targets (
  delivery_id INTEGER NOT NULL,
  target_id   INTEGER NOT NULL,
  PRIMARY KEY (delivery_id, target_id),
  FOREIGN KEY (delivery_id) REFERENCES edu_deliveries(id) ON DELETE CASCADE,
  FOREIGN KEY (target_id)   REFERENCES targets(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_dt_delivery ON edu_delivery_targets(delivery_id);

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
  last_reminded_at TEXT,
  remind_count INTEGER NOT NULL DEFAULT 0,             -- 自動の催促を送った回数(D1 の上限に使う)
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
