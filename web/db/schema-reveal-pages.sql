-- テナントごとの種明かしページ(G29)。既存の data_dir/reveal.html は「既定」として残す。
-- HTML の本体はテナントの data_dir/reveal-pages/reveal-<id>.html に置き、DB にはメタ情報だけ持つ。
-- 参照のないキャンペーンは既定(reveal.html)を使うので、既存キャンペーンの到達画面は変わらない。
CREATE TABLE IF NOT EXISTS reveal_pages (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  name         TEXT NOT NULL,
  storage_name TEXT NOT NULL,            -- data_dir/reveal-pages/ 直下のファイル名(reveal-<id>.html)
  created_by   TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at   TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_reveal_pages_tenant ON reveal_pages(tenant_id);
