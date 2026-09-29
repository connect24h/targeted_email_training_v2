-- 不審メールの解析で照合する、テナントが登録した条件(G42)。
-- 当たった時は所見に「登録した条件に一致」を足すだけで、自動で送ったり消したりはしない。
CREATE TABLE IF NOT EXISTS suspicious_mail_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    name TEXT NOT NULL,                                  -- 条件の名前(所見に出す)
    kind TEXT NOT NULL CHECK (kind IN ('sender', 'subject_keyword', 'url_domain')),
    value TEXT NOT NULL,                                 -- 送信者のアドレスかドメイン、件名の語、URL のドメイン
    is_active INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1)),
    created_by TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_suspicious_mail_rules_tenant ON suspicious_mail_rules(tenant_id, is_active);
