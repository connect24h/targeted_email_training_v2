-- パスワード設定のトークン(招待と再設定)。1回だけ使え、72時間で切れる。
-- トークンの平文はメールの URL にだけ入れ、ここには sha256 のハッシュだけを保存する。
CREATE TABLE IF NOT EXISTS user_password_tokens (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL,
  token_hash  TEXT NOT NULL UNIQUE,                 -- hash('sha256', 平文のトークン)
  purpose     TEXT NOT NULL CHECK (purpose IN ('invite', 'reset')),
  expires_at  TEXT NOT NULL,
  used_at     TEXT,                                 -- パスワードを設定した日時(1回だけ)
  revoked_at  TEXT,                                 -- 新しいトークンの発行、パスワードの設定・変更で無効にした日時
  created_by  INTEGER,                              -- 発行した管理画面ユーザ(削除されても残すので外部キーにしない)
  created_at  TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_user_password_tokens_user ON user_password_tokens(user_id, used_at, revoked_at);
