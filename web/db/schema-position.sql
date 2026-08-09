-- 役職マスタ。役職名(targets.title)と役職カテゴリの対応表。
--
-- targets.position_category は各対象者が値として保持し続ける(非正規化)。
-- 本テーブルはその値を決めるための「マッピング定義」であり、外部キーではない。
-- 理由: position_category は PipelineRunner(メール差し込み・訓練結果CSV)、logs、report が
-- 広く参照しており、FK 化すると全経路に JOIN が要る。また CSV 取込は「マスタに無い役職でも
-- 行を落とさない」方針のため、FK 制約と両立しない。
--
-- マスタ編集後は positions.php の apply で targets へ一括反映する。
-- 反映漏れ(マスタとtargetsのズレ)は coverage の stale で検知できる。
CREATE TABLE IF NOT EXISTS position_masters (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  tenant_id  INTEGER NOT NULL,
  title      TEXT NOT NULL,                 -- 役職名。targets.title と完全一致で突合する
  category   TEXT NOT NULL CHECK (category IN ('役員', '管理職', '一般従業員')),
  sort_order INTEGER NOT NULL DEFAULT 0,
  note       TEXT,                          -- 「推定」等のメモ。画面でバッジ表示する
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  UNIQUE (tenant_id, title),
  FOREIGN KEY (tenant_id) REFERENCES tenants(id)
);
CREATE INDEX IF NOT EXISTS idx_position_masters_tenant
  ON position_masters(tenant_id, category);
