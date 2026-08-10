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


def validate_data_dir(data_dir):
    """送信プロセスが必要なcampaign directoryへ書き込めることを事前検証する。"""
    for path in (data_dir, os.path.join(data_dir, "logs")):
        if not os.path.isdir(path):
            return f"送信ディレクトリがありません: {path}"
        if not os.access(path, os.W_OK | os.X_OK):
            return f"送信ディレクトリへ書き込みできません: {path}"
    return None


def claim_batch(conn, busy_campaign_ids=None):
    """queued かつ scheduled_at 到来のバッチを1件、排他クレームして返す。

    busy_campaign_ids: 現在このワーカーで送信中のキャンペーンID集合。
    同一キャンペーンの多重送信を防ぐため、これらのキャンペーンのバッチは
    クレームしない(1キャンペーン=1プロセスを保証)。
    """
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    busy = list(busy_campaign_ids or [])
    if busy:
        placeholders = ",".join("?" * len(busy))
        sql = (
            f"SELECT * FROM send_schedule "
            f"WHERE status='queued' AND scheduled_at <= ? "
            f"AND campaign_id NOT IN ({placeholders}) "
            f"ORDER BY scheduled_at LIMIT 1"
        )
        row = conn.execute(sql, (now, *busy)).fetchone()
    else:
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


def start_batch(conn, batch):
    """バッチの前処理(CSV/ビーコン生成、同期)を行い、send_email.py を
    非同期(Popen)で起動する。起動できたら実行中ジョブ dict を返す。
    起動しなかった(窓外/停止/前処理失敗)場合は None を返す。

    前処理は短時間で、data_dir 別・tracking_id 別ファイルのため並列でも
    衝突しない。実送信(send_email)だけを非同期化して複数キャンペーンを
    並行送信する。
    """
    cid = batch["campaign_id"]
    campaign = conn.execute("SELECT * FROM campaigns WHERE id=?", (cid,)).fetchone()
    if campaign is None:
        _fail(conn, batch, "campaign not found")
        return None

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
        return None

    data_dir = campaign["data_dir"]
    if not data_dir:
        _fail(conn, batch, "data_dir 未設定")
        return None

    # 緊急停止フラグ: あればこのバッチを cancelled にして送信しない
    if os.path.isdir(data_dir) and os.path.isfile(os.path.join(data_dir, "stop_sending.flag")):
        conn.execute("UPDATE send_schedule SET status='cancelled' WHERE id=?", (batch["id"],))
        conn.commit()
        log(f"batch {batch['id']} 停止フラグ検出 → cancelled")
        return None

    permission_error = validate_data_dir(data_dir)
    if permission_error is not None:
        _fail(conn, batch, permission_error)
        return None

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
    # 前処理(CSV・ビーコン・リンク・添付生成)は launch 時に実施済み。
    # ここでは再生成せず、生成物が揃っているかだけ確認して送信のみ行う。
    list_csv = os.path.join(data_dir, "list.csv")
    if not os.path.isfile(list_csv):
        _fail(conn, batch, f"送信データ未生成(list.csv なし)。launch をやり直してください: {list_csv}")
        return None
    try:
        # 実送信は非同期起動(並行送信の要)。完了は main ループが poll で回収する。
        proc = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    except Exception as e:  # noqa: BLE001
        _fail(conn, batch, f"起動例外: {e}")
        return None

    # 送信開始と同時にキャンペーンを running にする(送信制御パネルで「送信中」と
    # 表示させるため)。従来は finish_batch(完了時)で running にしていたが、それだと
    # 送信中はずっと scheduled のままで、パネルに送信中が出なかった。
    conn.execute(
        "UPDATE campaigns SET status='running' WHERE id=? AND status IN ('scheduled','draft')",
        (cid,),
    )
    conn.commit()

    return {
        "proc": proc,
        "batch": batch,
        "cid": cid,
        "data_dir": data_dir,
        "batch_no": batch["batch_no"],
    }


def finish_batch(conn, job):
    """非同期送信プロセスの完了後処理: done 遷移・send_status 同期・delivery_log。"""
    proc = job["proc"]
    batch = job["batch"]
    cid = job["cid"]
    data_dir = job["data_dir"]
    rc = proc.returncode
    if rc != 0:
        stderr = b""
        try:
            stderr = proc.stderr.read() if proc.stderr else b""
        except Exception:  # noqa: BLE001
            pass
        _fail(conn, batch, f"send_email 失敗(rc={rc}): {stderr.decode('utf-8', 'ignore')[:300]}")
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
    log(f"送信完了 campaign={cid} batch={job['batch_no']}")


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
    # 失敗後に後続batchが走り続けないよう同一campaignを安全側へ停止する。
    conn.execute(
        "UPDATE send_schedule SET status='cancelled' "
        "WHERE campaign_id=? AND status='queued'",
        (batch["campaign_id"],),
    )
    conn.execute(
        "UPDATE campaigns SET status='paused' "
        "WHERE id=? AND status IN ('draft','scheduled','running')",
        (batch["campaign_id"],),
    )
    conn.commit()
    log(f"batch {batch['id']} 失敗: {reason}")


def main():
    max_concurrent = int(os.environ.get("TET2_MAX_CONCURRENT", "3"))
    log(f"tet2-worker 起動 (最大同時送信数={max_concurrent})")
    running_jobs = []  # [{proc, batch, cid, data_dir, batch_no}, ...]
    # 完了回収は頻繁に、新規クレームは POLL_SEC ごとに行う。
    loop_sec = 2
    since_poll = POLL_SEC  # 起動直後に一度クレームを試す
    while True:
        try:
            conn = db()
            # 1. 完了したジョブを回収
            still_running = []
            for job in running_jobs:
                if job["proc"].poll() is None:
                    still_running.append(job)  # まだ送信中
                else:
                    try:
                        finish_batch(conn, job)
                    except Exception as e:  # noqa: BLE001
                        log(f"finish_batch 例外 campaign={job['cid']}: {e}")
            running_jobs = still_running

            # 2. 空きがあれば新規バッチをクレームして起動(POLL_SEC 間隔)
            if since_poll >= POLL_SEC:
                since_poll = 0
                while len(running_jobs) < max_concurrent:
                    busy = {j["cid"] for j in running_jobs}
                    batch = claim_batch(conn, busy)
                    if batch is None:
                        break  # 実行可能なバッチが無い
                    job = start_batch(conn, batch)
                    if job is not None:
                        running_jobs.append(job)
                    # start_batch が None(窓外/停止/失敗)でも次のバッチを試す
            conn.close()
        except Exception as e:  # noqa: BLE001
            log(f"ループ例外: {e}")
        time.sleep(loop_sec)
        since_poll += loop_sec


if __name__ == "__main__":
    main()
