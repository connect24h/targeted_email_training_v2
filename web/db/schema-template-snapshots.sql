-- 配信予約時のシナリオ版。元テンプレートの編集・削除後も配信時点の証跡を保持する。
CREATE TABLE IF NOT EXISTS campaign_template_snapshots (
  campaign_id   INTEGER NOT NULL,
  tenant_id     INTEGER NOT NULL,
  launch_sequence INTEGER NOT NULL CHECK (launch_sequence > 0),
  content_no    INTEGER NOT NULL CHECK (content_no > 0),
  role          TEXT NOT NULL CHECK (role IN ('subject', 'body', 'phish_login')),
  template_id   INTEGER NOT NULL,
  name          TEXT NOT NULL,
  format        TEXT NOT NULL,
  content       TEXT NOT NULL,
  auth_flag     INTEGER,
  captured_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  PRIMARY KEY (campaign_id, launch_sequence, content_no, role),
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
CREATE INDEX IF NOT EXISTS idx_template_snapshots_tenant
  ON campaign_template_snapshots(tenant_id, campaign_id);
