-- 分野のタグ(G09)。2階層(親のタグと子のタグ)で、1つの設問に複数付けられる。
-- tenant_id NULL は共有のタグ(編集はシステム管理者だけ)。子のタグの親は1段目のタグに限る(3段目は API で拒む)。
-- 既存の教材カテゴリ(edu_categories)と設問の category_id はそのまま残す(カテゴリ別の集計は変えない)。
CREATE TABLE IF NOT EXISTS edu_tags (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER,                                  -- NULL = 共有
  parent_id   INTEGER,                                  -- NULL = 親のタグ(1段目)
  name        TEXT NOT NULL,
  description TEXT,
  sort_order  INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  FOREIGN KEY (parent_id) REFERENCES edu_tags(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_tags_tenant ON edu_tags(tenant_id);
CREATE INDEX IF NOT EXISTS idx_edu_tags_parent ON edu_tags(parent_id);
-- 同じ持ち主(テナントか共有)の同じ親の下で、同じ名前は1つだけ(Excel の取込で名前から引くため)
CREATE UNIQUE INDEX IF NOT EXISTS idx_edu_tags_scope_name
  ON edu_tags(COALESCE(tenant_id, 0), COALESCE(parent_id, 0), name);

-- 設問とタグの結び付け(設問経由でテナントを担保する。tenant_id なし)
CREATE TABLE IF NOT EXISTS edu_question_tags (
  question_id INTEGER NOT NULL,
  tag_id      INTEGER NOT NULL,
  PRIMARY KEY (question_id, tag_id),
  FOREIGN KEY (question_id) REFERENCES edu_questions(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id)      REFERENCES edu_tags(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_question_tags_tag ON edu_question_tags(tag_id);
