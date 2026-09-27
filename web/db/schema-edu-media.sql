-- 教材のページ画像と、答えた直後の答え合わせ（08 設計書の G16、G18）。既存テーブルには触れない。
-- 画像の実体は /opt/training/tet2-data/edu-media/ 配下に置き、ここにはサーバーが決めたファイル名だけを持つ。

CREATE TABLE IF NOT EXISTS edu_material_pages (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  material_id INTEGER NOT NULL,
  page_no     INTEGER NOT NULL,                  -- 1 始まり
  image_name  TEXT NOT NULL,                     -- 例 page-001.jpg
  page_text   TEXT NOT NULL DEFAULT '',          -- PDF から抜き出したページの文字(画像の代替テキスト)
  width       INTEGER,
  height      INTEGER,
  UNIQUE (material_id, page_no),
  FOREIGN KEY (material_id) REFERENCES edu_materials(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_edu_material_pages_material ON edu_material_pages(material_id, page_no);

-- 答え合わせをした設問の解答。配信の割当と設問の組で1回だけ記録し、正解を見た後に選び直させない。
CREATE TABLE IF NOT EXISTS edu_answer_locks (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  assignment_id INTEGER NOT NULL,
  question_id   INTEGER NOT NULL,
  answer        TEXT NOT NULL,                   -- JSON配列(0始まりindex)
  is_correct    INTEGER NOT NULL,
  answered_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  UNIQUE (assignment_id, question_id),
  FOREIGN KEY (assignment_id) REFERENCES edu_assignments(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES edu_questions(id)
);
