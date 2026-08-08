#!/usr/bin/env python3
"""TET v2 キャンペーン物理パージ.

論理削除(campaigns.deleted_at)から一定日数(既定90日=3か月)を過ぎた
キャンペーンの実データを物理削除する。cron から日次で起動する想定。

削除対象:
  1. 物理ファイル(tracking_id 単位): ビーコン画像 / link-html / QR添付
  2. data_dir 一式(campaign_{id}/ の CSV・logs)
  3. CASCADE されないテーブル: events, delivery_log
  4. edu_deliveries.phish_campaign_id は NULL 化(教育配信自体は残す)
  5. campaigns 本体を DELETE(CASCADE で send_schedule / campaign_contents /
     campaign_targets / campaign_report_snapshots が自動削除される)

--dry-run で対象一覧と削除予定ファイルを表示するだけ(実削除しない)。
"""
import argparse
import os
import sqlite3
import sys
from datetime import datetime

DB_PATH = "/opt/training/tet2-db/tet2.sqlite"
DOCROOT = "/var/www/html"
ATTACHMENT_DIR = "/opt/training/bin/Attachment"
LOG_PATH = "/opt/training/tet2-data/purge.log"
RETENTION_DAYS = 90  # 3か月


def log(msg: str) -> None:
    line = f"{datetime.now().strftime('%Y-%m-%d %H:%M:%S')} - {msg}"
    print(line, flush=True)
    try:
        with open(LOG_PATH, "a", encoding="utf-8") as f:
            f.write(line + "\n")
    except OSError:
        pass


def target_files(tracking_id: str):
    """tracking_id に対応する物理ファイルパスを列挙する。"""
    return [
        os.path.join(DOCROOT, f"kunren-beacon-{tracking_id}.png"),
        os.path.join(DOCROOT, f"link-{tracking_id}.html"),
        # QR添付は拡張子が docx/pdf/html/zip 等。glob 相当で prefix 一致を拾う。
    ]


def qr_attachment_files(tracking_id: str):
    """QR添付(kunren-qr-{tid}.*)を prefix 一致で列挙する。"""
    prefix = f"kunren-qr-{tracking_id}."
    found = []
    if os.path.isdir(ATTACHMENT_DIR):
        for fn in os.listdir(ATTACHMENT_DIR):
            if fn.startswith(prefix):
                found.append(os.path.join(ATTACHMENT_DIR, fn))
    return found


def rmtree(path: str, dry: bool) -> int:
    """ディレクトリを再帰削除。削除ファイル数を返す。"""
    import shutil
    if not os.path.isdir(path):
        return 0
    count = sum(len(files) for _, _, files in os.walk(path))
    if not dry:
        shutil.rmtree(path, ignore_errors=True)
    return count


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true", help="対象を表示するだけで削除しない")
    ap.add_argument("--days", type=int, default=RETENTION_DAYS, help="保持日数(既定90)")
    args = ap.parse_args()
    dry = args.dry_run

    if not os.path.isfile(DB_PATH):
        log(f"DB が見つかりません: {DB_PATH}")
        return 1

    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA foreign_keys = ON")  # CASCADE 有効化

    # パージ対象: deleted_at が args.days 日より前
    cutoff_expr = f"datetime('now','localtime','-{args.days} days')"
    rows = conn.execute(
        f"SELECT c.id, c.name, c.deleted_at, c.data_dir, t.slug "
        f"FROM campaigns c LEFT JOIN tenants t ON t.id = c.tenant_id "
        f"WHERE c.deleted_at IS NOT NULL AND c.deleted_at < {cutoff_expr} "
        f"ORDER BY c.id"
    ).fetchall()

    if not rows:
        log(f"パージ対象なし(保持{args.days}日){' [DRY-RUN]' if dry else ''}")
        conn.close()
        return 0

    log(f"パージ対象 {len(rows)} 件(保持{args.days}日){' [DRY-RUN]' if dry else ''}")
    total_files = 0

    for c in rows:
        cid = c["id"]
        # 対象者の tracking_id を集める(物理ファイル削除用)
        tids = [r["tracking_id"] for r in conn.execute(
            "SELECT tracking_id FROM campaign_targets WHERE campaign_id = ?", [cid]
        ).fetchall()]

        file_count = 0
        for tid in tids:
            for path in target_files(tid) + qr_attachment_files(tid):
                if os.path.isfile(path):
                    if not dry:
                        try:
                            os.remove(path)
                        except OSError as e:
                            log(f"  ファイル削除失敗 {path}: {e}")
                            continue
                    file_count += 1

        # data_dir 一式
        data_dir = c["data_dir"] or ""
        # data_dir が空でも slug から標準パスを推定
        if not data_dir and c["slug"]:
            data_dir = f"/opt/training/tet2-data/{c['slug']}/campaign_{cid}"
        dir_files = rmtree(data_dir, dry) if data_dir else 0

        # CASCADE されないテーブル
        if not dry:
            conn.execute("DELETE FROM events WHERE campaign_id = ?", [cid])
            conn.execute("DELETE FROM delivery_log WHERE campaign_id = ?", [cid])
            conn.execute("UPDATE edu_deliveries SET phish_campaign_id = NULL WHERE phish_campaign_id = ?", [cid])
            # campaigns 本体(CASCADE 連動削除)
            conn.execute("DELETE FROM campaigns WHERE id = ?", [cid])
            conn.commit()

        total_files += file_count + dir_files
        log(f"  campaign {cid} \"{c['name']}\" (削除日={c['deleted_at']}): "
            f"ファイル{file_count}件 + data_dir {dir_files}件"
            f"{' [DRY-RUN]' if dry else ' 削除完了'}")

    log(f"パージ完了: {len(rows)} キャンペーン, 合計 {total_files} ファイル{' [DRY-RUN]' if dry else ''}")
    conn.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
