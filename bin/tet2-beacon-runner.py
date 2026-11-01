#!/usr/bin/env python3
"""TET v2 ビーコン生成ランナー(training として systemd が起動する)。

なぜ必要か:
  配信予約のビーコン/QR/添付生成は training ユーザーで走らせる必要がある
  (送信worker send_email.py と operation.log を共有し、所有を training に一本化するため)。
  従来は Web(www-data)から `sudo -u training` で呼んでいたが、2026-10-21 の apache2
  更新で apache2.service に InaccessiblePaths=-/etc/sudoers 等が入り、Web プロセスから
  sudo が使えなくなった。そこで Web はキューに依頼ファイルを置くだけにし、systemd の
  path 起動でこのスクリプトを training として動かす。Web は sudo も systemctl も呼ばない。

プロトコル(キュー: /opt/training/tet2-data/_beacon_queue):
  Web が <reqid>.req(JSON: data_dir, beacon_base) を atomic rename で置く。
  このスクリプトが *.req を順に処理し、create_beacon_files.py を実行して
  <reqid>.res(JSON: rc, output, finished_at) を atomic rename で書き、.req を消す。
  Web は <reqid>.res を待って rc/output を読み、.res を消す。
  実体は data_dir 配下にしか作らないため、1件ずつ逐次処理で十分(launch は .launch.lock で直列)。
"""
import glob
import json
import os
import stat
import subprocess
import sys
import time

QUEUE_DIR = os.environ.get("TET2_BEACON_QUEUE_DIR", "/opt/training/tet2-data/_beacon_queue")
BEACON_SCRIPT = os.environ.get("TET2_BEACON_SCRIPT", "/opt/training/bin/create_beacon_files.py")
PYTHON = "/usr/bin/python3"
STALE_SECONDS = 3600  # 1時間以上前の取り残しは掃除する
# 生成物の書き込み先はこの配下だけを許す。依頼(www-data作成)に細工された data_dir で
# training 権限が別の場所へ書くのを防ぐ。
DATA_ROOT = os.environ.get("TET2_BEACON_DATA_ROOT", "/opt/training/tet2-data")


def _atomic_write(path, payload):
    # 権限境界をまたぐキュー。www-data が同名の symlink を先に置いても、それを辿って
    # training 権限で別の場所へ書かないよう、既存を消してから O_EXCL|O_NOFOLLOW で作る。
    tmp = path + "." + str(os.getpid()) + ".tmp"
    for stale in (tmp, path):
        try:
            os.unlink(stale)
        except FileNotFoundError:
            pass
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False)
        f.flush()
        os.fsync(f.fileno())
    os.replace(tmp, path)


def _process(req_path):
    reqid = os.path.basename(req_path)[:-4]  # strip .req
    res_path = os.path.join(QUEUE_DIR, reqid + ".res")
    try:
        # symlink を辿らず、通常ファイルであることを確かめてから読む(www-data が置く領域のため)。
        fd = os.open(req_path, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            if not stat.S_ISREG(os.fstat(fd).st_mode):
                raise ValueError("req は通常ファイルではありません")
            with os.fdopen(fd, encoding="utf-8") as f:
                req = json.load(f)
        except BaseException:
            os.close(fd)
            raise
        data_dir = str(req["data_dir"])
        beacon_base = str(req.get("beacon_base") or "")
        resolved = os.path.realpath(data_dir)
        root = os.path.realpath(DATA_ROOT)
        if resolved != root and not resolved.startswith(root + os.sep):
            raise ValueError("data_dir が許可範囲外です: " + data_dir)
        cmd = [PYTHON, BEACON_SCRIPT, "--data-dir", resolved]
        if beacon_base != "":
            cmd += ["--beacon-base", beacon_base]
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
        output = (proc.stdout + proc.stderr).strip().splitlines()
        payload = {"rc": proc.returncode, "output": output[-5:], "finished_at": time.time()}
    except Exception as e:  # noqa: BLE001 失敗も必ず結果として Web へ返す
        payload = {"rc": 97, "output": [f"runner error: {type(e).__name__}: {e}"],
                   "finished_at": time.time()}
    try:
        _atomic_write(res_path, payload)
    finally:
        try:
            os.unlink(req_path)
        except FileNotFoundError:
            pass


def _sweep_stale():
    now = time.time()
    for path in glob.glob(os.path.join(QUEUE_DIR, "*")):
        try:
            if now - os.path.getmtime(path) > STALE_SECONDS:
                os.unlink(path)
        except OSError:
            pass


def main():
    if not os.path.isdir(QUEUE_DIR):
        return 0
    # systemd の path 起動は「*.req が無→有」の変化でしか再起動しない。サービス実行中に
    # 届いた依頼の取り残しを防ぐため、*.req が無くなるまでこのプロセス内で繰り返す。
    while True:
        pending = sorted(glob.glob(os.path.join(QUEUE_DIR, "*.req")))
        if not pending:
            break
        for req_path in pending:
            _process(req_path)
    _sweep_stale()
    return 0


if __name__ == "__main__":
    sys.exit(main())
