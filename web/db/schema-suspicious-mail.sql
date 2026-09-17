-- 不審メール（報告された .eml）の受付・解析・判定。
-- raw .eml は tenants.data_dir/suspicious/<sha256>.eml に保存し、DB にはパスだけ持つ。
CREATE TABLE IF NOT EXISTS suspicious_mails (
    id INTEGER PRIMARY KEY,
    tenant_id INTEGER REFERENCES tenants(id) ON DELETE CASCADE,
    source TEXT NOT NULL,
    report_mail_id INTEGER REFERENCES report_mails(id) ON DELETE SET NULL,
    reporter_email TEXT,
    uploaded_by TEXT,
    raw_path TEXT NOT NULL,
    sha256 TEXT NOT NULL,
    raw_bytes INTEGER NOT NULL,
    message_id TEXT,
    subject TEXT,
    from_email TEXT,
    from_name TEXT,
    received_at TEXT NOT NULL,
    is_training INTEGER NOT NULL DEFAULT 0,
    tracking_id TEXT,
    analysis_json TEXT NOT NULL,
    findings_json TEXT NOT NULL,
    score INTEGER NOT NULL DEFAULT 0,
    suggested_category TEXT NOT NULL DEFAULT 'undetermined',
    category TEXT NOT NULL DEFAULT 'undetermined',
    status TEXT NOT NULL DEFAULT 'open',
    priority TEXT NOT NULL DEFAULT 'normal',
    assigned_to TEXT,
    note TEXT,
    analyzer_version INTEGER NOT NULL DEFAULT 1,
    reputation_checked_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (tenant_id, sha256)
);

CREATE TABLE IF NOT EXISTS suspicious_mail_history (
    id INTEGER PRIMARY KEY,
    suspicious_mail_id INTEGER NOT NULL REFERENCES suspicious_mails(id) ON DELETE CASCADE,
    actor_email TEXT NOT NULL,
    field TEXT NOT NULL,
    old_value TEXT,
    new_value TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- 外部評判（VirusTotal 等）の参照結果キャッシュ。ファイルは sha256、URL は正規化 URL をキーにする。
CREATE TABLE IF NOT EXISTS reputation_cache (
    id INTEGER PRIMARY KEY,
    provider TEXT NOT NULL DEFAULT 'virustotal',
    kind TEXT NOT NULL,
    lookup_key TEXT NOT NULL,
    found INTEGER NOT NULL DEFAULT 0,
    malicious INTEGER NOT NULL DEFAULT 0,
    suspicious INTEGER NOT NULL DEFAULT 0,
    harmless INTEGER NOT NULL DEFAULT 0,
    undetected INTEGER NOT NULL DEFAULT 0,
    result_json TEXT,
    fetched_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (provider, kind, lookup_key)
);

CREATE INDEX IF NOT EXISTS idx_suspicious_mails_tenant_status ON suspicious_mails(tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_suspicious_mails_received_at ON suspicious_mails(received_at);
CREATE INDEX IF NOT EXISTS idx_suspicious_mail_history_mail ON suspicious_mail_history(suspicious_mail_id);
