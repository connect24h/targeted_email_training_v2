import importlib.util
import sqlite3
import tempfile
import unittest
from pathlib import Path
from unittest import mock


WORKER_PATH = Path(__file__).resolve().parents[1] / "tet2-worker.py"
SPEC = importlib.util.spec_from_file_location("tet2_worker", WORKER_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("tet2-worker.pyを読み込めません")
tet2_worker = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(tet2_worker)


class WorkerFailureTest(unittest.TestCase):
    def setUp(self) -> None:
        self.conn = sqlite3.connect(":memory:")
        self.conn.row_factory = sqlite3.Row
        self.conn.executescript(
            """
            CREATE TABLE campaigns (
                id INTEGER PRIMARY KEY,
                status TEXT NOT NULL
            );
            CREATE TABLE send_schedule (
                id INTEGER PRIMARY KEY,
                campaign_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0
            );
            INSERT INTO campaigns (id, status) VALUES (82, 'running');
            INSERT INTO send_schedule (id, campaign_id, status) VALUES
                (32, 82, 'running'),
                (33, 82, 'queued');
            """
        )

    def tearDown(self) -> None:
        self.conn.close()

    def test_should_pause_campaign_and_cancel_queued_batches_on_failure(self) -> None:
        batch = self.conn.execute(
            "SELECT * FROM send_schedule WHERE id=32"
        ).fetchone()

        tet2_worker._fail(self.conn, batch, "permission denied")

        campaign = self.conn.execute(
            "SELECT status FROM campaigns WHERE id=82"
        ).fetchone()
        schedules = self.conn.execute(
            "SELECT id, status, attempts FROM send_schedule ORDER BY id"
        ).fetchall()
        self.assertEqual(campaign["status"], "paused")
        self.assertEqual(dict(schedules[0]), {"id": 32, "status": "failed", "attempts": 1})
        self.assertEqual(dict(schedules[1]), {"id": 33, "status": "cancelled", "attempts": 0})

    def test_should_reject_data_directory_not_writable_by_worker(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            (Path(temp_dir) / "logs").mkdir()
            with mock.patch.object(tet2_worker.os, "access", return_value=False):
                error = tet2_worker.validate_data_dir(temp_dir)

        self.assertIsNotNone(error)
        self.assertIn("書き込み", error)

    def test_should_preserve_paused_campaign_when_running_batch_stops_cleanly(self) -> None:
        self.conn.execute("DELETE FROM send_schedule WHERE id=33")
        self.conn.execute("UPDATE campaigns SET status='paused' WHERE id=82")
        self.conn.commit()
        batch = self.conn.execute("SELECT * FROM send_schedule WHERE id=32").fetchone()
        proc = mock.Mock(returncode=0, stderr=None)

        tet2_worker.finish_batch(
            self.conn,
            {
                "proc": proc,
                "batch": batch,
                "cid": 82,
                "data_dir": "/nonexistent",
                "batch_no": 1,
            },
        )

        status = self.conn.execute("SELECT status FROM campaigns WHERE id=82").fetchone()["status"]
        self.assertEqual(status, "paused")


class WorkerClaimTest(unittest.TestCase):
    def setUp(self) -> None:
        self.conn = sqlite3.connect(":memory:")
        self.conn.row_factory = sqlite3.Row
        self.conn.executescript(
            """
            CREATE TABLE campaigns (id INTEGER PRIMARY KEY, status TEXT NOT NULL);
            CREATE TABLE send_schedule (
                id INTEGER PRIMARY KEY,
                campaign_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                scheduled_at TEXT NOT NULL,
                claimed_at TEXT,
                worker_pid INTEGER
            );
            INSERT INTO campaigns VALUES (1, 'draft'), (2, 'scheduled');
            INSERT INTO send_schedule (id, campaign_id, status, scheduled_at) VALUES
                (1, 1, 'queued', '2000-01-01 00:00:00'),
                (2, 2, 'queued', '2000-01-01 00:00:00');
            """
        )

    def tearDown(self) -> None:
        self.conn.close()

    def test_should_claim_only_scheduled_or_running_campaign(self) -> None:
        batch = tet2_worker.claim_batch(self.conn)

        self.assertIsNotNone(batch)
        self.assertEqual(batch["campaign_id"], 2)
        draft_status = self.conn.execute(
            "SELECT status FROM send_schedule WHERE id=1"
        ).fetchone()["status"]
        self.assertEqual(draft_status, "queued")


class WorkerPartialFailureTest(unittest.TestCase):
    def test_should_sync_accepted_rows_before_pausing_failed_batch(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            Path(temp_dir, "list.csv").write_text(
                "乱数列,送信フラグ,メールアドレス（会社）\n"
                "0000000001,1,first@example.test\n"
                "0000000002,0,second@example.test\n",
                encoding="utf-8",
            )
            conn = sqlite3.connect(":memory:")
            conn.row_factory = sqlite3.Row
            try:
                conn.executescript(
                    """
                    CREATE TABLE campaigns (id INTEGER PRIMARY KEY, status TEXT);
                    CREATE TABLE send_schedule (
                        id INTEGER PRIMARY KEY, campaign_id INTEGER, status TEXT,
                        attempts INTEGER DEFAULT 0
                    );
                    CREATE TABLE campaign_targets (
                        campaign_id INTEGER, tracking_id TEXT, send_status TEXT, sent_at TEXT
                    );
                    CREATE TABLE delivery_log (
                        campaign_id INTEGER, tracking_id TEXT, to_email TEXT,
                        result TEXT, smtp_message TEXT
                    );
                    INSERT INTO campaigns VALUES (82, 'running');
                    INSERT INTO send_schedule VALUES (32, 82, 'running', 0);
                    INSERT INTO campaign_targets VALUES
                        (82, '0000000001', 'pending', NULL),
                        (82, '0000000002', 'pending', NULL);
                    """
                )
                batch = conn.execute("SELECT * FROM send_schedule WHERE id=32").fetchone()
                tet2_worker.finish_batch(conn, {
                    "proc": mock.Mock(returncode=1), "batch": batch, "cid": 82,
                    "data_dir": temp_dir, "batch_no": 1,
                })
                rows = conn.execute(
                    "SELECT tracking_id, send_status FROM campaign_targets ORDER BY tracking_id"
                ).fetchall()
                self.assertEqual([row["send_status"] for row in rows], ["sent", "pending"])
                self.assertEqual(conn.execute("SELECT status FROM campaigns WHERE id=82").fetchone()[0], "paused")
                self.assertEqual(conn.execute("SELECT COUNT(*) FROM delivery_log").fetchone()[0], 1)
            finally:
                conn.close()


class WorkerPipeDeadlockTest(unittest.TestCase):
    """送信プロセスの stdout をパイプで受けるとバッファ満杯で止まる回帰の防止。

    main ループは子の出力を読まないため、stdout=PIPE だと大量出力(1,876通)で
    パイプバッファ(64KB)が満杯になり子が書き込みブロックして送信が停止する
    (2026-08-10、106通目で停止)。stdout は捨て、stderr はファイルへ逃がす。
    """

    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        data_dir = Path(self.tmp.name)
        (data_dir / "list.csv").write_text("項番,送信先情報\n1,a@example.test\n", encoding="utf-8")
        self.conn = sqlite3.connect(":memory:")
        self.conn.row_factory = sqlite3.Row
        self.conn.executescript(
            """
            CREATE TABLE campaigns (
                id INTEGER PRIMARY KEY, status TEXT, data_dir TEXT,
                weekdays_only INTEGER, business_start TEXT, business_end TEXT
            );
            """
        )
        # 営業時間の窓外で繰り延べられないよう、weekdays_only=0 で常に窓内にする
        self.conn.execute(
            "INSERT INTO campaigns VALUES (1, 'scheduled', ?, 0, NULL, NULL)",
            (str(data_dir),),
        )
        self.batch = {
            "id": 1,
            "campaign_id": 1,
            "batch_no": 1,
            "koban_from": 1,
            "koban_to": 1,
            "interval_sec": 3,
            "data_dir": str(data_dir),
        }

    def tearDown(self) -> None:
        self.conn.close()
        self.tmp.cleanup()

    def test_should_not_pipe_stdout_of_send_process(self) -> None:
        captured = {}

        class FakeProc:
            returncode = None

            def poll(self):
                return None

        def fake_popen(args, **kwargs):
            captured["args"] = args
            captured["kwargs"] = kwargs
            return FakeProc()

        with mock.patch.object(tet2_worker, "validate_data_dir", return_value=None), \
                mock.patch.object(tet2_worker.subprocess, "Popen", side_effect=fake_popen):
            job = tet2_worker.start_batch(self.conn, self.batch)

        self.assertIsNotNone(job)
        # stdout をパイプで受けないこと(受けると読み手不在で子が詰まる)
        self.assertIsNot(captured["kwargs"].get("stdout"), tet2_worker.subprocess.PIPE)
        self.assertEqual(captured["kwargs"].get("stdout"), tet2_worker.subprocess.DEVNULL)
        # stderr はパイプではなくファイルハンドルへ(バッファ上限が無く詰まらない)
        self.assertIsNot(captured["kwargs"].get("stderr"), tet2_worker.subprocess.PIPE)
        self.assertEqual(captured["args"][captured["args"].index("--campaign-id") + 1], "1")


class BusinessWindowHolidayTest(unittest.TestCase):
    """平日限定が土日に加えて祝日も除外することを固定する。

    2026-08-11(火・山の日)に平日限定キャンペーンが送信されてしまった事故の再発防止。
    """

    @staticmethod
    def _campaign(weekdays_only=1, business_start=None, business_end=None):
        # Row 互換の dict-like でよい(実装は ["key"] アクセスのみ)。
        return {
            "weekdays_only": weekdays_only,
            "business_start": business_start,
            "business_end": business_end,
        }

    def test_holiday_is_non_business_day(self) -> None:
        from datetime import datetime
        # 2026-08-11 は火曜だが山の日(祝)。
        self.assertTrue(tet2_worker.is_non_business_day(datetime(2026, 8, 11, 10, 0)))

    def test_weekday_is_business_day(self) -> None:
        from datetime import datetime
        self.assertFalse(tet2_worker.is_non_business_day(datetime(2026, 8, 12, 10, 0)))

    def test_saturday_is_non_business_day(self) -> None:
        from datetime import datetime
        self.assertTrue(tet2_worker.is_non_business_day(datetime(2026, 8, 15, 10, 0)))

    def test_weekdays_only_blocks_holiday(self) -> None:
        from datetime import datetime
        # 平日限定ONなら祝日は窓外。
        self.assertFalse(
            tet2_worker.in_business_window(datetime(2026, 8, 11, 10, 0), self._campaign(weekdays_only=1))
        )
        # 平日限定OFFなら祝日でも窓内(曜日/時間制限なし)。
        self.assertTrue(
            tet2_worker.in_business_window(datetime(2026, 8, 11, 10, 0), self._campaign(weekdays_only=0))
        )

    def test_next_window_skips_holiday(self) -> None:
        from datetime import datetime
        # 2026-08-10(月)夜に繰り延べ → 翌8/11は山の日(祝)なので飛ばし、8/12(水)09:00へ。
        nxt = tet2_worker.next_window(
            datetime(2026, 8, 10, 20, 0), self._campaign(weekdays_only=1, business_start="09:00")
        )
        self.assertEqual(nxt.strftime("%Y-%m-%d %H:%M"), "2026-08-12 09:00")


if __name__ == "__main__":
    unittest.main()
