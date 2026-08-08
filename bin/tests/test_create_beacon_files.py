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


if __name__ == "__main__":
    unittest.main()
