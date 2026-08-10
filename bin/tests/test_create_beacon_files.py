import sys
import tempfile
import unittest
from pathlib import Path


BIN_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BIN_DIR))

import create_beacon_files  # noqa: E402


class ReadListCsvTest(unittest.TestCase):
    def test_should_preserve_leading_zero_in_tracking_id(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            csv_path = Path(temp_dir) / "list.csv"
            csv_path.write_text(
                "乱数列,送信先情報\n0012345678,user@example.com\n",
                encoding="utf-8",
            )

            data = create_beacon_files.read_list_csv(csv_path)

            self.assertEqual(data.loc[0, "乱数列"], "0012345678")


class GeneratedArtifactValidationTest(unittest.TestCase):
    def test_should_reject_required_attachment_when_path_is_empty(self) -> None:
        rows = create_beacon_files.pd.DataFrame(
            [{"乱数列": "0000000001", "添付ファイル番号": "1", "添付ファイル": ""}]
        )

        with tempfile.TemporaryDirectory() as temp_dir:
            web_root = Path(temp_dir)
            (web_root / "kunren-beacon-0000000001.png").write_bytes(b"png")
            (web_root / "link-0000000001.html").write_text("ok", encoding="utf-8")

            errors = create_beacon_files.validate_generated_artifacts(rows, web_root)

        self.assertTrue(any("必須添付" in error for error in errors))

    def test_should_accept_complete_generated_artifacts(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            attachment = root / "attachment.pdf"
            attachment.write_bytes(b"pdf")
            (root / "kunren-beacon-0000000002.png").write_bytes(b"png")
            (root / "link-0000000002.html").write_text("ok", encoding="utf-8")
            rows = create_beacon_files.pd.DataFrame(
                [{
                    "乱数列": "0000000002",
                    "添付ファイル番号": "1",
                    "添付ファイル": str(attachment),
                }]
            )

            errors = create_beacon_files.validate_generated_artifacts(rows, root)

        self.assertEqual(errors, [])


if __name__ == "__main__":
    unittest.main()
