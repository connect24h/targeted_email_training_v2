-- 送信エンドポイントのマスタ(ビーコンベースURLと送信元アドレスの登録元)。
-- tenant_id NULL は全テナント共通(共有)＝システム管理者だけが管理する。テナントの行は所有する組織が管理。
-- kind='beacon' は http(s):// の URL、kind='from' はメールアドレス。
-- キャンペーン/コンテンツはここから複数選び、起動時に対象者へ振り分ける(campaign_targets.resolved_*)。
-- 行が無くても従来どおり動く(単数列→config.ini→既定IP)。共有の既定IP 1行だけを初期投入する。
CREATE TABLE IF NOT EXISTS send_endpoints (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id   INTEGER REFERENCES tenants(id) ON DELETE CASCADE,  -- NULL は共有(全テナント共通)
  kind        TEXT NOT NULL CHECK (kind IN ('beacon','from')),
  value       TEXT NOT NULL,                     -- beacon は URL、from はメールアドレス
  label       TEXT,                              -- 画面表示用の名前(任意)
  sort_order  INTEGER NOT NULL DEFAULT 0,
  is_active   INTEGER NOT NULL DEFAULT 1,
  created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  UNIQUE (tenant_id, kind, value)
);
CREATE INDEX IF NOT EXISTS idx_send_endpoints ON send_endpoints(tenant_id, kind, is_active, sort_order);

-- 既定のビーコンベースIP(従来の PipelineRunner の既定)を共有の beacon として1件入れる。
-- 顧客固有のホスト名・アドレスはここには入れない(配備時に本番 DB へ別途登録する)。
INSERT INTO send_endpoints (tenant_id, kind, value, label, sort_order)
SELECT NULL, 'beacon', 'http://85.131.251.224/', '既定の追跡サーバ', 0
WHERE NOT EXISTS (
  SELECT 1 FROM send_endpoints WHERE tenant_id IS NULL AND kind = 'beacon' AND value = 'http://85.131.251.224/'
);
