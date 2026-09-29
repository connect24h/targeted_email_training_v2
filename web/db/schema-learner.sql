-- 受講者のマイページ(L1〜L5、L7)。
-- users.target_id(schema.sql)は role='learner' のアカウントがつながる対象者。1対象者につき learner は1つ。
CREATE UNIQUE INDEX IF NOT EXISTS idx_users_target ON users(target_id) WHERE target_id IS NOT NULL;

-- 受講の回(再受講の履歴)。割当(edu_assignments)1件に対し、提出のたびに1行。
-- edu_assignments と edu_responses は「最新の提出の回」の結果のまま(レポートはそれを数える)。
-- ここには前の回も含めて、回ごとの点数、合否、解答、開始と提出の日時を残す(消さない)。
-- completed_at が NULL の行は受講中の回(1割当に高々1行)。
CREATE TABLE IF NOT EXISTS edu_attempts (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id     INTEGER NOT NULL,
  assignment_id INTEGER NOT NULL,
  attempt_no    INTEGER NOT NULL,                     -- 1 から。割当の中で何回目か
  is_retake     INTEGER NOT NULL DEFAULT 0,           -- 1 = 完了の後にマイページから受け直した回
  started_at    TEXT,
  completed_at  TEXT,                                 -- 提出の日時。NULL は受講中
  total_score   INTEGER,
  max_score     INTEGER,
  percentage    INTEGER,
  passed        INTEGER,                              -- 1/0。合格点のない配信(アウェアネス)は NULL
  answers       TEXT,                                 -- JSON [{"question_id":int,"answer":int[],"is_correct":bool,"score_earned":int}](answer は元の選択肢の番号)
  test_started_at TEXT,                               -- この回で確認テストを始めた日時(テスト中は教材を閉じる配信で使う)
  material_version INTEGER,                            -- この回を受けた時点の教材の版(G20)。教材のない配信は NULL
  created_at    TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  UNIQUE (assignment_id, attempt_no),
  FOREIGN KEY (tenant_id)     REFERENCES tenants(id),
  FOREIGN KEY (assignment_id) REFERENCES edu_assignments(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_edu_attempts_assignment ON edu_attempts(assignment_id, attempt_no);
CREATE INDEX IF NOT EXISTS idx_edu_attempts_tenant ON edu_attempts(tenant_id);
