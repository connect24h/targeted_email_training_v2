CREATE TABLE IF NOT EXISTS report_mails (
    id INTEGER PRIMARY KEY,
    message_id_hash TEXT NOT NULL UNIQUE,
    message_id TEXT,
    content_hash TEXT NOT NULL,
    maildir_file TEXT NOT NULL,
    received_at TEXT NOT NULL,
    date_header TEXT,
    from_email TEXT,
    subject_head TEXT,
    parse_status TEXT NOT NULL,
    parse_error TEXT,
    ingest_mode TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

CREATE TABLE IF NOT EXISTS report_mail_matches (
    id INTEGER PRIMARY KEY,
    report_mail_id INTEGER NOT NULL REFERENCES report_mails(id) ON DELETE CASCADE,
    tracking_id TEXT NOT NULL,
    tenant_id INTEGER,
    campaign_id INTEGER,
    method TEXT NOT NULL,
    evidence TEXT,
    status TEXT NOT NULL,
    event_id INTEGER,
    decided_by TEXT,
    decided_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    UNIQUE (report_mail_id, tracking_id)
);

CREATE INDEX IF NOT EXISTS idx_report_mails_received_at ON report_mails(received_at);
CREATE INDEX IF NOT EXISTS idx_report_mail_matches_tenant_status ON report_mail_matches(tenant_id, status);
CREATE INDEX IF NOT EXISTS idx_report_mail_matches_tracking_id ON report_mail_matches(tracking_id);
