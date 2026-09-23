import importlib.util
import sqlite3
import tempfile
import unittest
from contextlib import closing
from pathlib import Path
from unittest import mock


SEND_EMAIL_PATH = Path(__file__).resolve().parents[1] / "send_email.py"
SPEC = importlib.util.spec_from_file_location("send_email", SEND_EMAIL_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("send_email.pyを読み込めません")
send_email = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(send_email)


class RecipientEligibilityTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory()
        self.data_dir = str(Path(self.temp.name) / "campaign")
        self.db_path = str(Path(self.temp.name) / "fixture.sqlite")
        with closing(sqlite3.connect(self.db_path)) as conn:
            conn.executescript(
                """
                CREATE TABLE campaigns (id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, data_dir TEXT);
                CREATE TABLE targets (id INTEGER PRIMARY KEY, tenant_id INTEGER, email TEXT, status TEXT);
                CREATE TABLE campaign_targets (
                    campaign_id INTEGER, target_id INTEGER, tracking_id TEXT, send_status TEXT
                );
                INSERT INTO targets VALUES (10, 1, 'active@example.test', 'active');
                INSERT INTO campaign_targets VALUES (2, 10, '0000000001', 'pending');
                """
            )
            conn.execute("INSERT INTO campaigns VALUES (2, 1, 'running', ?)", (self.data_dir,))
            conn.commit()
        self.guard = send_email.RecipientEligibilityGuard(2, self.data_dir, self.db_path)
        self.csv_row = {"乱数列": "0000000001", "メールアドレス（会社）": "active@example.test"}

    def tearDown(self) -> None:
        self.guard.close()
        self.temp.cleanup()

    def change(self, sql, values=()) -> None:
        with closing(sqlite3.connect(self.db_path)) as conn:
            conn.execute(sql, values)
            conn.commit()

    def test_should_allow_active_target_in_campaign_tenant(self) -> None:
        self.guard.assert_can_send(self.csv_row)

    def test_should_reject_target_archived_after_launch(self) -> None:
        self.change("UPDATE targets SET status='archived' WHERE id=10")
        with self.assertRaises(send_email.RecipientEligibilityError):
            self.guard.assert_can_send(self.csv_row)

    def test_should_reject_target_moved_to_other_tenant(self) -> None:
        self.change("UPDATE targets SET tenant_id=2 WHERE id=10")
        with self.assertRaises(send_email.RecipientEligibilityError):
            self.guard.assert_can_send(self.csv_row)

    def test_should_reject_target_email_changed_after_launch(self) -> None:
        self.change("UPDATE targets SET email='changed@example.test' WHERE id=10")
        with self.assertRaises(send_email.RecipientEligibilityError):
            self.guard.assert_can_send(self.csv_row)

    def test_should_allow_manual_resume_of_deferred_row(self) -> None:
        self.change("UPDATE campaign_targets SET send_status='deferred' WHERE tracking_id='0000000001'")
        self.guard.assert_can_send(self.csv_row)

    def test_should_reject_db_row_already_marked_sent(self) -> None:
        self.change("UPDATE campaign_targets SET send_status='sent' WHERE tracking_id='0000000001'")
        with self.assertRaises(send_email.RecipientEligibilityError):
            self.guard.assert_can_send(self.csv_row)

    def test_should_reject_missing_tracking_id(self) -> None:
        with self.assertRaises(send_email.RecipientEligibilityError):
            self.guard.assert_can_send({**self.csv_row, "乱数列": ""})

    def test_should_reject_tracking_id_not_in_campaign(self) -> None:
        with self.assertRaises(send_email.RecipientEligibilityError):
            self.guard.assert_can_send({**self.csv_row, "乱数列": "0000009999"})

    def test_should_reject_missing_original_email(self) -> None:
        with self.assertRaises(send_email.RecipientEligibilityError):
            self.guard.assert_can_send({**self.csv_row, "メールアドレス（会社）": ""})

    def test_should_reject_campaign_paused_after_batch_started(self) -> None:
        self.change("UPDATE campaigns SET status='paused' WHERE id=2")
        with self.assertRaises(send_email.RecipientEligibilityError):
            self.guard.assert_can_send(self.csv_row)

    def test_should_reject_data_dir_swapped_after_launch(self) -> None:
        self.change("UPDATE campaigns SET data_dir=? WHERE id=2", (self.temp.name,))
        with self.assertRaises(send_email.RecipientEligibilityError):
            self.guard.assert_can_send(self.csv_row)

    def test_should_fail_closed_when_database_cannot_be_read(self) -> None:
        self.guard.close()
        with self.assertRaises(send_email.RecipientEligibilityError):
            self.guard.assert_can_send(self.csv_row)

    def test_should_stop_sender_before_smtp_when_target_is_archived(self) -> None:
        self.change("UPDATE targets SET status='archived' WHERE id=10")
        sender = send_email.TargetedEmailSender.__new__(send_email.TargetedEmailSender)
        sender.recipient_guard = self.guard
        sender.attachment_dir = str(Path(self.temp.name) / "Attachment")
        sender.email_list = send_email.pd.DataFrame([{
            **self.csv_row,
            "送信先情報": "active@example.test",
            "送信元メールアドレス": "sender@example.test",
            "送信フラグ": "",
            "添付ファイル番号": "",
            "添付ファイル": "",
        }])
        sender.logger = mock.Mock()
        sender.operation_logger = mock.Mock()
        sender.update_status = mock.Mock()
        sender.validate_email_data = mock.Mock(return_value=(1, 0, []))
        sender.validate_email_format = mock.Mock(return_value=True)
        sender.check_stop_file = mock.Mock(return_value=False)
        sender.get_subject_template = mock.Mock(return_value="subject")
        sender.get_body_template = mock.Mock(return_value="body")
        sender.replace_placeholders = mock.Mock(side_effect=lambda value, row: value)
        sender.send_email = mock.Mock(return_value=True)
        with mock.patch.object(send_email.smtplib, "SMTP"):
            with self.assertRaises(send_email.RecipientEligibilityError):
                sender.send_bulk_emails(interval=0)
        sender.send_email.assert_not_called()

    def test_should_stop_remaining_rows_if_target_is_archived_mid_batch(self) -> None:
        self.change("INSERT INTO targets VALUES (11, 1, 'second@example.test', 'active')")
        self.change("INSERT INTO campaign_targets VALUES (2, 11, '0000000002', 'pending')")
        sender = send_email.TargetedEmailSender.__new__(send_email.TargetedEmailSender)
        sender.recipient_guard = self.guard
        sender.attachment_dir = str(Path(self.temp.name) / "Attachment")
        sender.email_list = send_email.pd.DataFrame([
            {**self.csv_row, "送信先情報": "active@example.test", "送信元メールアドレス": "sender@example.test", "送信フラグ": "", "添付ファイル番号": "", "添付ファイル": ""},
            {"乱数列": "0000000002", "メールアドレス（会社）": "second@example.test", "送信先情報": "second@example.test", "送信元メールアドレス": "sender@example.test", "送信フラグ": "", "添付ファイル番号": "", "添付ファイル": ""},
        ])
        sender.logger = mock.Mock()
        sender.operation_logger = mock.Mock()
        sender.update_status = mock.Mock()
        sender.validate_email_data = mock.Mock(return_value=(2, 0, []))
        sender.validate_email_format = mock.Mock(return_value=True)
        sender.check_stop_file = mock.Mock(return_value=False)
        sender.get_subject_template = mock.Mock(return_value="subject")
        sender.get_body_template = mock.Mock(return_value="body")
        sender.replace_placeholders = mock.Mock(side_effect=lambda value, row: value)
        sender.check_mail_delivery = mock.Mock(return_value=True)
        sender.update_send_flag = mock.Mock()

        def accept_first(*args, **kwargs):
            self.change("UPDATE targets SET status='archived' WHERE id=11")
            return True

        sender.send_email = mock.Mock(side_effect=accept_first)
        with mock.patch.object(send_email.smtplib, "SMTP"), mock.patch.object(send_email.time, "sleep"):
            with self.assertRaises(send_email.RecipientEligibilityError):
                sender.send_bulk_emails(interval=0)
        sender.send_email.assert_called_once()

    def test_should_recheck_after_smtp_connection_before_sendmail(self) -> None:
        sender = send_email.TargetedEmailSender.__new__(send_email.TargetedEmailSender)
        sender.recipient_guard = self.guard
        sender.logger = mock.Mock()
        sender.validate_email_format = mock.Mock(return_value=True)
        smtp = mock.Mock()

        def enter_connection():
            self.change("UPDATE targets SET status='archived' WHERE id=10")
            return smtp

        smtp_context = mock.Mock()
        smtp_context.__enter__ = mock.Mock(side_effect=enter_connection)
        smtp_context.__exit__ = mock.Mock(return_value=False)
        with mock.patch.object(send_email.smtplib, "SMTP", return_value=smtp_context):
            with self.assertRaises(send_email.RecipientEligibilityError):
                sender.send_email(
                    "active@example.test", "sender@example.test", "subject", "body",
                    eligibility_row=self.csv_row,
                )
        smtp.sendmail.assert_not_called()


if __name__ == "__main__":
    unittest.main()
