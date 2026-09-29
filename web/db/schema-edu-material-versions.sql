-- 教材の版(G20)。差し替え(replace_pdf / 内容の差し替え)のたびに edu_materials.version を1つ上げ、履歴を1行残す。
-- 古いページ画像は保持しない(replace_pdf は置き場を入れ替える)。ここにはメタ情報(版、差し替え日時、差し替えた人、元のファイル名、ページ数)だけ残す。
CREATE TABLE IF NOT EXISTS edu_material_versions (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  material_id  INTEGER NOT NULL REFERENCES edu_materials(id) ON DELETE CASCADE,
  version      INTEGER NOT NULL,
  replaced_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  replaced_by  TEXT,
  source_name  TEXT,
  page_count   INTEGER NOT NULL DEFAULT 0,
  UNIQUE (material_id, version)
);
CREATE INDEX IF NOT EXISTS idx_edu_material_versions_material ON edu_material_versions(material_id);
