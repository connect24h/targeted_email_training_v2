-- 教育の配信の自動化(毎月の配信の系列)。既存テーブルには触れない。
-- edu_deliveries の追加列(send_invites など)は MigrationRunner の ensureAdditiveColumns が既存DBへ足す。

-- 毎月の配信の規則。settings に元になる配信の設定(JSON)を持ち、edu_scheduler.php が
-- next_run_at の来た系列から配信を1つ予約の状態で作り、next_run_at を翌月に進める。
CREATE TABLE IF NOT EXISTS edu_delivery_series (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  title         TEXT NOT NULL,
  settings      TEXT NOT NULL,                              -- 配信の設定(JSON)
  day_of_month  INTEGER NOT NULL CHECK (day_of_month BETWEEN 1 AND 28),
  time_of_day   TEXT NOT NULL,                              -- 'HH:MM'
  deadline_days INTEGER NOT NULL DEFAULT 14 CHECK (deadline_days BETWEEN 1 AND 90),
  next_run_at   TEXT NOT NULL,                              -- 'YYYY-MM-DD HH:MM:SS'
  end_date      TEXT,                                       -- 'YYYY-MM-DD'。この日より後の回は作らない
  is_active     INTEGER NOT NULL DEFAULT 1,
  created_by    INTEGER,
  created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id)  REFERENCES tenants(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_edu_delivery_series_due ON edu_delivery_series(is_active, next_run_at);
CREATE INDEX IF NOT EXISTS idx_edu_delivery_series_tenant ON edu_delivery_series(tenant_id);
CREATE INDEX IF NOT EXISTS idx_edu_deliveries_series ON edu_deliveries(series_id);
