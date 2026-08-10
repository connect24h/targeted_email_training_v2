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


if __name__ == "__main__":
    unittest.main()
