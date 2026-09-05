import email
import unittest
from unittest import mock

from test_send_email_fail_closed import send_email


class MessageIdTest(unittest.TestCase):
    def send_message(self, tracking_id=None):
        sender = send_email.TargetedEmailSender.__new__(send_email.TargetedEmailSender)
        sender.logger = mock.Mock()
        with mock.patch.object(send_email.smtplib, "SMTP") as smtp, mock.patch.object(
            send_email, "formatdate", return_value="Sat, 05 Sep 2026 23:00:00 +0900"
        ):
            smtp.return_value.__enter__.return_value.sendmail.return_value = {}
            args = {} if tracking_id is None else {"tracking_id": tracking_id}
            self.assertTrue(sender.send_email(
                "recipient@example.net", "sender@example.org", "件名", "本文", **args
            ))
            raw = smtp.return_value.__enter__.return_value.sendmail.call_args.args[2]
        return email.message_from_string(raw)

    def test_tracking_id_preserves_leading_zero_and_has_random_suffix(self):
        first = self.send_message("0987654321")
        second = self.send_message("0987654321")
        self.assertRegex(first["Message-ID"], r"^<t0987654321\.[0-9a-f]{16,}@example\.org>$")
        self.assertNotEqual(first["Message-ID"], second["Message-ID"])
        self.assertEqual(first["From"], "sender@example.org")
        self.assertEqual(first["To"], "recipient@example.net")
        self.assertEqual(str(email.header.make_header(email.header.decode_header(first["Subject"]))), "件名")
        self.assertEqual(first["Date"], "Sat, 05 Sep 2026 23:00:00 +0900")
        self.assertEqual(first.get_payload(0).get_payload(decode=True).decode(), "本文")

    def test_legacy_call_uses_make_msgid(self):
        with mock.patch.object(send_email, "make_msgid", return_value="<legacy@example.org>") as factory:
            self.assertEqual(self.send_message()["Message-ID"], "<legacy@example.org>")
        factory.assert_called_once_with()

    def test_invalid_or_empty_tracking_id_uses_legacy_format(self):
        for value in ("", "123", "１２３４５６７８９０", "1234567890\r\nX: injected", float("nan")):
            with self.subTest(value=value), mock.patch.object(send_email, "make_msgid", return_value="<legacy@example.org>"):
                self.assertEqual(self.send_message(value)["Message-ID"], "<legacy@example.org>")
