-- 認証訓練の入力本文はイベント/レポートから分離し、AEAD暗号文だけを保存する。
CREATE TABLE IF NOT EXISTS credential_captures (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id    INTEGER NOT NULL,
  campaign_id  INTEGER NOT NULL,
  tracking_id  TEXT NOT NULL,
  auth_type    TEXT NOT NULL,
  nonce        TEXT NOT NULL,
  ciphertext   TEXT NOT NULL,
  created_at   TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_credential_captures_campaign
  ON credential_captures(tenant_id, campaign_id, created_at);
