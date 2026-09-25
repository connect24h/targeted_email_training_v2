-- アンケート(訓練後・教育後の振り返り)。設計: docs/spec/07-seculio-benchmark-update-design.md の U7。
-- すべてテナントに属する。配信を1件でも作ったアンケートは設問を編集できない(集計の一貫性)。
-- 匿名アンケートは、回答(survey_responses)と割当(survey_assignments)を結ばない。
--   回答済みかどうかは割当で管理し、回答本文は assignment_id を NULL にして保存する。
--   時刻による突き合わせを防ぐため、匿名時は回答日時・割当の回答日時を日付までに丸める。

CREATE TABLE IF NOT EXISTS surveys (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL,
  title        TEXT NOT NULL,
  description  TEXT,
  status       TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published','closed')),
  is_anonymous INTEGER NOT NULL DEFAULT 0,
  created_by   INTEGER,
  created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
CREATE INDEX IF NOT EXISTS idx_surveys_tenant ON surveys(tenant_id, status);

CREATE TABLE IF NOT EXISTS survey_questions (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  survey_id     INTEGER NOT NULL,
  section       TEXT,
  sort_order    INTEGER NOT NULL DEFAULT 0,
  question_type TEXT NOT NULL CHECK (question_type IN ('single','multiple','text')),
  title         TEXT NOT NULL,
  options       TEXT NOT NULL DEFAULT '[]',   -- JSON 配列(選択肢の文字列)。text では空
  is_required   INTEGER NOT NULL DEFAULT 0,
  show_if       TEXT,                          -- JSON {"question_index":int,"option":int} 前の設問の回答で表示
  FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_survey_questions_survey ON survey_questions(survey_id, sort_order);

CREATE TABLE IF NOT EXISTS survey_deliveries (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER NOT NULL,
  survey_id   INTEGER NOT NULL,
  title       TEXT NOT NULL,
  status      TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
  deadline    TEXT,                            -- 'YYYY-MM-DD HH:MM:SS'(localtime)。NULL は期限なし
  group_ids   TEXT NOT NULL DEFAULT '[]',      -- 配信作成時に指定したグループ(記録用)
  invited_at  TEXT,                            -- 案内メールを最後に送った日時
  created_by  INTEGER,
  created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  closed_at   TEXT,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  FOREIGN KEY (survey_id) REFERENCES surveys(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_survey_deliveries_tenant ON survey_deliveries(tenant_id, status);

CREATE TABLE IF NOT EXISTS survey_assignments (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id        INTEGER NOT NULL,
  delivery_id      INTEGER NOT NULL,
  target_id        INTEGER NOT NULL,
  access_token     TEXT NOT NULL UNIQUE,       -- 32桁 hex。受講と同じトークン方式
  status           TEXT NOT NULL DEFAULT 'assigned' CHECK (status IN ('assigned','answered')),
  answered_at      TEXT,                        -- 匿名時は日付のみ
  invited_at       TEXT,
  last_reminded_at TEXT,
  UNIQUE (delivery_id, target_id),
  FOREIGN KEY (tenant_id)   REFERENCES tenants(id),
  FOREIGN KEY (delivery_id) REFERENCES survey_deliveries(id) ON DELETE CASCADE,
  FOREIGN KEY (target_id)   REFERENCES targets(id)
);
CREATE INDEX IF NOT EXISTS idx_survey_assignments_delivery ON survey_assignments(delivery_id, status);

CREATE TABLE IF NOT EXISTS survey_responses (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  delivery_id   INTEGER NOT NULL,
  assignment_id INTEGER UNIQUE,                -- 記名時のみ。匿名時は NULL
  submitted_at  TEXT NOT NULL,                 -- 匿名時は日付のみ
  is_test       INTEGER NOT NULL DEFAULT 0,     -- テスト用対象者の回答。集計から除外する(本人は特定しない)
  FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  FOREIGN KEY (delivery_id)   REFERENCES survey_deliveries(id) ON DELETE CASCADE,
  FOREIGN KEY (assignment_id) REFERENCES survey_assignments(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_survey_responses_delivery ON survey_responses(delivery_id);

CREATE TABLE IF NOT EXISTS survey_answers (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  response_id INTEGER NOT NULL,
  question_id INTEGER NOT NULL,
  value       TEXT NOT NULL,                   -- JSON。single/multiple は選択 index の配列、text は文字列
  UNIQUE (response_id, question_id),
  FOREIGN KEY (response_id) REFERENCES survey_responses(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES survey_questions(id) ON DELETE CASCADE
);
