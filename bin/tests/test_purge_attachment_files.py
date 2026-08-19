import importlib.util
import os
import sys
import tempfile
import unittest
from pathlib import Path

BIN_DIR = Path(__file__).resolve().parents[1]

# tet2-purge-campaigns.py はハイフンを含みモジュール名にできないため spec 経由で読み込む。
_spec = importlib.util.spec_from_file_location(
    "tet2_purge_campaigns", str(BIN_DIR / "tet2-purge-campaigns.py")
)
purge = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(purge)


class QrAttachmentFilesTest(unittest.TestCase):
    """接頭辞が可変になったため、削除は tracking_id を鍵に新旧命名を1ロジックで回収する。"""

    def _run_with_dir(self, files, tracking_id):
        with tempfile.TemporaryDirectory() as d:
            for fn in files:
                (Path(d) / fn).write_text("x", encoding="utf-8")
            orig = purge.ATTACHMENT_DIR
            purge.ATTACHMENT_DIR = d
            try:
                found = purge.qr_attachment_files(tracking_id)
            finally:
                purge.ATTACHMENT_DIR = orig
            return {os.path.basename(p) for p in found}

    def test_should_pick_legacy_and_new_names(self) -> None:
        tid = "1594109599"
        files = [
            f"kunren-qr-{tid}.pdf",   # 旧命名
            f"添付QR-{tid}.pdf",       # 新命名(接頭辞可変)
            f"kunren{tid}.png",        # 区切りなしフォールバック
            f"請求書-{tid}.zip",       # 日本語接頭辞
        ]
        found = self._run_with_dir(files, tid)
        self.assertEqual(found, set(files), "新旧すべての命名を tracking_id で回収する")

    def test_should_ignore_files_without_tracking_id(self) -> None:
        tid = "1594109599"
        files = [
            f"添付QR-{tid}.pdf",       # 対象
            "kunren-qr-9999999999.pdf",  # 別 tracking_id → 拾わない
            "unrelated.pdf",             # 無関係 → 拾わない
        ]
        found = self._run_with_dir(files, tid)
        self.assertEqual(found, {f"添付QR-{tid}.pdf"})

    def test_should_ignore_non_attachment_extensions(self) -> None:
        # tracking_id を含んでも生成拡張子でなければ誤削除しない(偽陽性防止)
        tid = "1594109599"
        files = [
            f"添付QR-{tid}.pdf",       # 対象
            f"note-{tid}.txt",          # 生成拡張子外 → 拾わない
            f"log-{tid}.csv",           # 生成拡張子外 → 拾わない
        ]
        found = self._run_with_dir(files, tid)
        self.assertEqual(found, {f"添付QR-{tid}.pdf"})


if __name__ == "__main__":
    unittest.main()
