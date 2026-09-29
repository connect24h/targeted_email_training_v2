-- 通知の文面(C2、G35)。テナントごとに、通知の種類ごとの件名と本文を上書きする。
-- 行のない種類は、コードに残した既定の文面(web/lib/NotificationTemplates.php)で送るので、既存の送信の文面は変わらない。
CREATE TABLE IF NOT EXISTS notification_templates (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
  kind       TEXT NOT NULL,                      -- edu_invite など(NotificationTemplates::KINDS)
  subject    TEXT NOT NULL,
  body       TEXT NOT NULL,
  updated_by TEXT,                               -- 保存したユーザのメールアドレス
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  UNIQUE (tenant_id, kind)
);
