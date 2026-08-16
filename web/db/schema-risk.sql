-- 個人リスクスコア(Human Risk Score)。
--
-- 訓練の行動(開封/クリック/認証入力/報告)と教育の受講状況を合成した、対象者ごとの
-- 総合指標。「誰に次の手を打つべきか」を判断するために使う。
--
-- edu_score_snapshots を拡張せず別テーブルにしたのは、あちらが「教育の点数」の
-- スキーマ(average_score / category_scores / respondent_count)であり、訓練行動を
-- 混ぜると average_score が二義になって既存行の解釈が壊れるため。
--
-- 内訳(phish_component / edu_component / report_credit / detail)を必ず保存する。
-- 一人運用では「なぜこの人が high なのか」を後から説明できることが重要で、
-- 合計値だけだと調整も説明もできなくなる。

CREATE TABLE IF NOT EXISTS human_risk_scores (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id       INTEGER NOT NULL,
  target_id       INTEGER NOT NULL,
  score           REAL    NOT NULL,               -- 0-100。高いほど高リスク
  band            TEXT    NOT NULL,               -- low / medium / high
  phish_component REAL    NOT NULL DEFAULT 0,     -- 訓練での失敗行動による加点
  edu_component   REAL    NOT NULL DEFAULT 0,     -- 教育の未受講・不合格による加点(合格は減点)
  report_credit   REAL    NOT NULL DEFAULT 0,     -- 報告(正しい行動)による減点
  detail          TEXT,                           -- JSON。素の件数の内訳(説明用)
  computed_date   TEXT    NOT NULL,               -- YYYY-MM-DD
  created_at      TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
  UNIQUE (tenant_id, target_id, computed_date),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id),
  FOREIGN KEY (target_id) REFERENCES targets(id)
);

CREATE INDEX IF NOT EXISTS idx_hrs_tenant_date ON human_risk_scores(tenant_id, computed_date);
CREATE INDEX IF NOT EXISTS idx_hrs_target      ON human_risk_scores(target_id, computed_date);
CREATE INDEX IF NOT EXISTS idx_hrs_band        ON human_risk_scores(tenant_id, computed_date, band);
