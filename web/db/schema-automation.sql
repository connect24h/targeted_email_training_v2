-- キャンペーンdraft自動生成ルール。実送信やsend_schedule作成は行わない。
CREATE TABLE IF NOT EXISTS campaign_automations (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id            INTEGER NOT NULL,
  name                 TEXT NOT NULL,
  source_campaign_id   INTEGER NOT NULL,
  frequency            TEXT NOT NULL CHECK (frequency IN ('monthly', 'quarterly')),
  day_of_month         INTEGER NOT NULL CHECK (day_of_month BETWEEN 1 AND 28),
  generation_lead_days INTEGER NOT NULL DEFAULT 7 CHECK (generation_lead_days BETWEEN 0 AND 90),
  time_mode            TEXT NOT NULL CHECK (time_mode IN ('fixed', 'random_window')),
  send_window_start    TEXT NOT NULL,
  send_window_end      TEXT,
  next_due_at          TEXT NOT NULL,
  assignment_mode      TEXT NOT NULL DEFAULT 'static'
                       CHECK (assignment_mode IN ('static', 'rotate')),
  max_occurrences      INTEGER CHECK (max_occurrences BETWEEN 1 AND 120),
  status               TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'paused')),
  created_by           INTEGER,
  created_at           TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at           TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  FOREIGN KEY (source_campaign_id) REFERENCES campaigns(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_campaign_automations_due
  ON campaign_automations(status, next_due_at);
CREATE INDEX IF NOT EXISTS idx_campaign_automations_tenant
  ON campaign_automations(tenant_id, status);

CREATE TABLE IF NOT EXISTS campaign_automation_groups (
  automation_id INTEGER NOT NULL,
  group_id      INTEGER NOT NULL,
  PRIMARY KEY (automation_id, group_id),
  FOREIGN KEY (automation_id) REFERENCES campaign_automations(id) ON DELETE CASCADE,
  FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_campaign_automation_groups_group
  ON campaign_automation_groups(group_id);

CREATE TABLE IF NOT EXISTS campaign_automation_runs (
  id                    INTEGER PRIMARY KEY AUTOINCREMENT,
  automation_id         INTEGER NOT NULL,
  occurrence_key        TEXT NOT NULL,
  selected_send_at      TEXT NOT NULL,
  generated_campaign_id INTEGER,
  status                TEXT NOT NULL CHECK (status IN ('claimed', 'generated', 'failed')),
  error_code            TEXT,
  created_at            TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  finished_at           TEXT,
  UNIQUE (automation_id, occurrence_key),
  FOREIGN KEY (automation_id) REFERENCES campaign_automations(id) ON DELETE CASCADE,
  FOREIGN KEY (generated_campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_campaign_automation_runs_status
  ON campaign_automation_runs(status, created_at);
