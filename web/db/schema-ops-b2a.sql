-- 段B2 の運用(B2-1 と B2-4)。既存テーブルの行には触れない。
-- targets の追加列(employee_no、memo)は MigrationRunner の ensureAdditiveColumns が既存DBへ足す(新しいDBは schema.sql)。

-- 従業員番号はテナントの中で一意(入れた人だけ。空の人は何人いてもよい)。CSV の取込はこの番号で先に照合する。
CREATE UNIQUE INDEX IF NOT EXISTS idx_targets_tenant_employee_no
  ON targets(tenant_id, employee_no) WHERE employee_no IS NOT NULL;

-- 自動の教育配信の実行履歴(G61)。自動の投入(訓練の失敗、新入社員)が配信1件を処理するたびに1行。
-- matched_count は条件に当てはまった人(前の実行で入った人も含む)、enrolled_count はその実行で新しく入れた人。
-- 当てはまった人の一覧は edu_assignments(created_at が入れた日時)から出す。誰を入れるかはこの表では変えない。
CREATE TABLE IF NOT EXISTS edu_auto_enroll_runs (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id      INTEGER NOT NULL,
  delivery_id    INTEGER NOT NULL,
  source         TEXT NOT NULL CHECK (source IN ('phishing_failure','new_target')),
  started_at     TEXT NOT NULL,
  finished_at    TEXT,
  matched_count  INTEGER NOT NULL DEFAULT 0,
  enrolled_count INTEGER NOT NULL DEFAULT 0,
  error          TEXT,                                -- 対象を決められなかった理由など。NULL は正常
  FOREIGN KEY (tenant_id)   REFERENCES tenants(id),
  FOREIGN KEY (delivery_id) REFERENCES edu_deliveries(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_edu_auto_enroll_runs_delivery ON edu_auto_enroll_runs(delivery_id, id);
CREATE INDEX IF NOT EXISTS idx_edu_auto_enroll_runs_tenant ON edu_auto_enroll_runs(tenant_id);
