-- 管理画面の保護(段階1、G43 と G44)。
-- users の TOTP の列(mfa_secret、mfa_enabled_at、mfa_last_step)は schema.sql と MigrationRunner の追加列にある。

-- 多要素認証の回復コード。1人10個、1回だけ使える。平文は登録の時に1回だけ画面に出し、ここには sha256 だけを残す。
CREATE TABLE IF NOT EXISTS user_mfa_recovery_codes (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL,
  code_hash   TEXT NOT NULL,                      -- hash('sha256', 区切りを除いた大文字のコード)
  used_at     TEXT,                               -- 使った日時(1回だけ)
  created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  UNIQUE (user_id, code_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_user_mfa_recovery_user ON user_mfa_recovery_codes(user_id, used_at);

-- パスワードと多要素認証の方針。tenant_id が NULL の1行が全テナント共通(システム管理者が決める)。
-- PasswordPolicy(12文字以上、4種のうち3種以上)より弱くはできない(CHECK と AdminSecurityPolicy の両方で守る)。
-- テナントの行と共通の行の両方があれば、厳しい方を使う。
CREATE TABLE IF NOT EXISTS tenant_security_policies (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER,                          -- NULL = 全テナント共通(システム管理者を含む)
  min_length    INTEGER NOT NULL DEFAULT 12 CHECK (min_length BETWEEN 12 AND 64),
  min_classes   INTEGER NOT NULL DEFAULT 3 CHECK (min_classes IN (3, 4)),
  require_mfa   INTEGER NOT NULL DEFAULT 0 CHECK (require_mfa IN (0, 1)),
  updated_by    INTEGER,                          -- 変えた管理画面ユーザ(削除されても残すので外部キーにしない)
  updated_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_tenant_security_policies_tenant ON tenant_security_policies(tenant_id) WHERE tenant_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS idx_tenant_security_policies_global ON tenant_security_policies((tenant_id IS NULL)) WHERE tenant_id IS NULL;
