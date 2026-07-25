#!/usr/bin/env python3
"""
TET v2 送信スケジューラワーカー。

send_schedule テーブルを 30 秒間隔でポーリングし、実行可能なバッチを排他クレームして
既存の create_beacon_files.py / send_email.py を起動する。
平日限定・営業時間はここで判定し、窓外なら scheduled_at を次の営業ウィンドウへ繰り延べる。

実行ユーザ: training（既存パイプラインと同じ）。systemd tet2-worker.service で常駐。
DB: /opt/training/tet2-db/tet2.sqlite（WAL, busy_timeout）。
"""
import os
import sqlite3
import subprocess
import sys
import time
from datetime import datetime, timedelta

DB_PATH = "/opt/training/tet2-db/tet2.sqlite"
PY = "/usr/bin/python3"
BIN = "/opt/training/bin"
POLL_SEC = 30


def db():
    conn = sqlite3.connect(DB_PATH, timeout=10)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=5000")
    conn.execute("PRAGMA foreign_keys=ON")
    return conn


def log(msg):
    print(f"[{datetime.now():%Y-%m-%d %H:%M:%S}] {msg}", flush=True)


def in_business_window(now, campaign):
    """平日限定・営業時間を判定。窓内なら True。"""
    if campaign["weekdays_only"] and now.weekday() >= 5:  # 5=土,6=日
        return False
    bs = campaign["business_start"]
    be = campaign["business_end"]
    if bs and be:
        cur = now.strftime("%H:%M")
        if not (bs <= cur <= be):
            return False
    return True


def next_window(now, campaign):
    """次に送信可能になる時刻を粗く算出（翌営業日の営業開始 or 当日営業開始）。"""
    bs = campaign["business_start"] or "09:00"
    hh, mm = (int(x) for x in bs.split(":"))
    candidate = now.replace(hour=hh, minute=mm, second=0, microsecond=0)
    if candidate <= now:
        candidate += timedelta(days=1)
    # 平日限定なら土日を飛ばす
    if campaign["weekdays_only"]:
        while candidate.weekday() >= 5:
            candidate += timedelta(days=1)
    return candidate


def claim_batch(conn):
    """queued かつ scheduled_at 到来のバッチを1件、排他クレームして返す。"""
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    row = conn.execute(
        """SELECT * FROM send_schedule
           WHERE status='queued' AND scheduled_at <= ?
           ORDER BY scheduled_at LIMIT 1""",
        (now,),
    ).fetchone()
    if row is None:
        return None
    # 排他: status が queued のままの行のみ running に遷移
    cur = conn.execute(
        "UPDATE send_schedule SET status='running', claimed_at=?, worker_pid=? "
        "WHERE id=? AND status='queued'",
        (now, os.getpid(), row["id"]),
    )
    conn.commit()
    if cur.rowcount != 1:
        return None  # 他ワーカーが先取り
    return row


def run_batch(conn, batch):
    cid = batch["campaign_id"]
    campaign = conn.execute("SELECT * FROM campaigns WHERE id=?", (cid,)).fetchone()
    if campaign is None:
        _fail(conn, batch, "campaign not found")
        return

    now = datetime.now()
    if not in_business_window(now, campaign):
        # 窓外 → 次ウィンドウへ繰り延べて queued に戻す
        nxt = next_window(now, campaign).strftime("%Y-%m-%d %H:%M:%S")
        conn.execute(
            "UPDATE send_schedule SET status='queued', scheduled_at=?, claimed_at=NULL, worker_pid=NULL WHERE id=?",
            (nxt, batch["id"]),
        )
        conn.commit()
        log(f"batch {batch['id']} 窓外 → {nxt} に繰り延べ")
        return

    data_dir = campaign["data_dir"]
    if not data_dir:
        _fail(conn, batch, "data_dir 未設定")
        return
    # data_dir は PipelineRunner が CSV 生成時に作成する（未存在でよい）。

    # 緊急停止フラグ: あればこのバッチを cancelled にして送信しない
    if os.path.isdir(data_dir) and os.path.isfile(os.path.join(data_dir, "stop_sending.flag")):
        conn.execute("UPDATE send_schedule SET status='cancelled' WHERE id=?", (batch["id"],))
        conn.commit()
        log(f"batch {batch['id']} 停止フラグ検出 → cancelled")
        return

    interval = str(batch["interval_sec"] or 3)
    args = [
        PY, f"{BIN}/send_email.py",
        "--data-dir", data_dir,
        "--interval", interval,
        "--start-koban", str(batch["koban_from"]),
        "--end-koban", str(batch["koban_to"]),
        "--auto-pause",
    ]
    log(f"送信開始 campaign={cid} batch={batch['batch_no']} koban {batch['koban_from']}-{batch['koban_to']}")
    try:
        # 前処理1: DB→CSV 生成（PipelineRunner を PHP CLI で実行。冪等）
        subprocess.run(
            ["/usr/bin/php", "-r",
             f'require "/var/www/html/tet2/lib/PipelineRunner.php"; PipelineRunner::generateCsv({cid});'],
            check=True, capture_output=True, timeout=300,
        )
        # 前処理2: ビーコン/リンク/添付を生成（冪等）
        subprocess.run(
            [PY, f"{BIN}/create_beacon_files.py", "--data-dir", data_dir],
            check=True, capture_output=True, timeout=600,
        )
        subprocess.run(args, check=True, capture_output=True, timeout=7200)
    except subprocess.CalledProcessError as e:
        _fail(conn, batch, f"send_email 失敗: {e.stderr.decode('utf-8', 'ignore')[:300]}")
        return
    except Exception as e:  # noqa: BLE001
        _fail(conn, batch, f"実行例外: {e}")
        return

    conn.execute("UPDATE send_schedule SET status='done' WHERE id=?", (batch["id"],))
    # list.csv の送信フラグ(1)を campaign_targets.send_status='sent' に同期
    _sync_send_status(conn, cid, data_dir)
    _log_delivery(conn, cid, data_dir)
    conn.execute(
        "UPDATE campaigns SET status='running' WHERE id=? AND status IN ('scheduled','draft')",
        (cid,),
    )
    # 全バッチ done ならキャンペーンを done に
    remaining = conn.execute(
        "SELECT COUNT(*) c FROM send_schedule WHERE campaign_id=? AND status!='done'",
        (cid,),
    ).fetchone()["c"]
    if remaining == 0:
        conn.execute("UPDATE campaigns SET status='done' WHERE id=?", (cid,))
    conn.commit()
    log(f"送信完了 campaign={cid} batch={batch['batch_no']}")


def _sync_send_status(conn, cid, data_dir):
    """list.csv の送信フラグ(1)を読み、campaign_targets.send_status を 'sent' に同期する。"""
    import csv
    list_csv = os.path.join(data_dir, "list.csv")
    if not os.path.isfile(list_csv):
        return
    sent_tids = []
    try:
        with open(list_csv, newline="", encoding="utf-8") as f:
            reader = csv.DictReader(f)
            for row in reader:
                flag = (row.get("送信フラグ") or "").strip()
                tid = (row.get("乱数列") or "").strip()
                if tid and flag in ("1", "1.0"):
                    sent_tids.append(tid)
    except Exception as e:  # noqa: BLE001
        log(f"send_status 同期の list.csv 読取失敗: {e}")
        return
    for tid in sent_tids:
        conn.execute(
            "UPDATE campaign_targets SET send_status='sent', sent_at=datetime('now','localtime') "
            "WHERE campaign_id=? AND tracking_id=? AND send_status!='sent'",
            (cid, tid),
        )
    conn.commit()


def _log_delivery(conn, cid, data_dir):
    """list.csv の送信成功行を delivery_log に記録する。"""
    import csv
    list_csv = os.path.join(data_dir, "list.csv")
    if not os.path.isfile(list_csv):
        return
    sent_rows = []
    try:
        with open(list_csv, newline="", encoding="utf-8") as f:
            reader = csv.DictReader(f)
            for row in reader:
                flag = (row.get("送信フラグ") or "").strip()
                tid = (row.get("乱数列") or "").strip()
                to_email = (row.get("メールアドレス（会社）") or "").strip()
                if tid and flag in ("1", "1.0"):
                    sent_rows.append((tid, to_email))
    except Exception as e:  # noqa: BLE001
        log(f"delivery_log 記録の list.csv 読取失敗: {e}")
        return
    for tid, to_email in sent_rows:
        exists = conn.execute(
            "SELECT 1 FROM delivery_log WHERE campaign_id=? AND tracking_id=? AND result='sent' LIMIT 1",
            (cid, tid),
        ).fetchone()
        if exists:
            continue
        conn.execute(
            "INSERT INTO delivery_log (campaign_id, tracking_id, to_email, result, smtp_message) "
            "VALUES (?, ?, ?, 'sent', NULL)",
            (cid, tid, to_email),
        )
    conn.commit()


def _fail(conn, batch, reason):
    conn.execute(
        "UPDATE send_schedule SET status='failed', attempts=attempts+1 WHERE id=?",
        (batch["id"],),
    )
    conn.commit()
    log(f"batch {batch['id']} 失敗: {reason}")


def main():
    log("tet2-worker 起動")
    while True:
        try:
            conn = db()
            batch = claim_batch(conn)
            if batch is not None:
                run_batch(conn, batch)
            conn.close()
        except Exception as e:  # noqa: BLE001
            log(f"ループ例外: {e}")
        time.sleep(POLL_SEC)


if __name__ == "__main__":
    main()
