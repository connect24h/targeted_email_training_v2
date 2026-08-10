import importlib.util
import tempfile
import unittest
from pathlib import Path
from unittest import mock


SEND_EMAIL_PATH = Path(__file__).resolve().parents[1] / "send_email.py"
SPEC = importlib.util.spec_from_file_location("send_email", SEND_EMAIL_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("send_email.pyを読み込めません")
send_email = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(send_email)


class RequiredAttachmentValidationTest(unittest.TestCase):
    def test_should_reject_empty_path_when_attachment_number_is_present(self) -> None:
        error = send_email.required_attachment_error(
            {"添付ファイル番号": "1", "添付ファイル": ""}
        )

        self.assertIsNotNone(error)
        self.assertIn("必須添付", error)

    def test_should_reject_unreadable_or_empty_attachment(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            empty = Path(temp_dir) / "empty.pdf"
            empty.touch()

            error = send_email.required_attachment_error(
                {"添付ファイル番号": "1", "添付ファイル": str(empty)}
            )

        self.assertIsNotNone(error)
        self.assertIn("空", error)

    def test_should_accept_row_without_attachment_requirement(self) -> None:
        error = send_email.required_attachment_error(
            {"添付ファイル番号": "", "添付ファイル": ""}
        )

        self.assertIsNone(error)

    def test_should_never_clear_operator_stop_flag(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            stop_file = Path(temp_dir) / "stop_sending.flag"
            stop_file.write_text("stopped\n", encoding="utf-8")
            sender = send_email.TargetedEmailSender.__new__(send_email.TargetedEmailSender)
            sender.stop_file = str(stop_file)
            sender.logger = mock.Mock()
            sender.operation_logger = mock.Mock()
            sender.update_status = mock.Mock()
            sender.validate_email_data = mock.Mock(side_effect=ValueError("fixture"))

            with self.assertRaises(ValueError):
                sender.send_bulk_emails()

            self.assertTrue(stop_file.exists())


if __name__ == "__main__":
    unittest.main()
