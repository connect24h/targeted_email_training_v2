import sys
import sqlite3
import tempfile
import unittest
from pathlib import Path


BIN_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BIN_DIR))

import create_beacon_files  # noqa: E402


class CredentialCaptureApprovalTest(unittest.TestCase):
    def test_should_enable_capture_only_for_matching_approved_tracking_id(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            db_path = Path(temp_dir) / "capture.sqlite"
            conn = sqlite3.connect(db_path)
            try:
                # 本文収集は「資格情報収集(link_mode='form') かつ 承認済み かつ auth_flag∈1..4」でのみ有効。
                # 現行は campaign_contents.link_mode、旧経路は campaigns.link_mode を見る。
                conn.executescript("""
                    CREATE TABLE campaigns (id INTEGER PRIMARY KEY,
                        credential_capture_approval_ref TEXT, deleted_at TEXT, link_mode TEXT);
                    CREATE TABLE campaign_targets (campaign_id INTEGER,
                        tracking_id TEXT, auth_flag INTEGER, content_no INTEGER);
                    CREATE TABLE campaign_contents (campaign_id INTEGER, content_no INTEGER, link_mode TEXT);
                    -- 1: 承認済み + form(contents) → 収集可
                    INSERT INTO campaigns VALUES (1, 'synthetic-approval', NULL, NULL);
                    INSERT INTO campaign_targets VALUES (1, '0123456789', 1, 1);
                    INSERT INTO campaign_contents VALUES (1, 1, 'form');
                    -- 2: 承認なし → 不可
                    INSERT INTO campaigns VALUES (2, NULL, NULL, NULL);
                    INSERT INTO campaign_targets VALUES (2, '1111111111', 1, 1);
                    INSERT INTO campaign_contents VALUES (2, 1, 'form');
                    -- 3: 承認済み だが link 形式 → 不可(承認があっても収集しない)
                    INSERT INTO campaigns VALUES (3, 'approved-but-link', NULL, NULL);
                    INSERT INTO campaign_targets VALUES (3, '2222222222', 1, 1);
                    INSERT INTO campaign_contents VALUES (3, 1, 'link');
                    -- 4: 旧経路(campaign_contents 無し)。承認済み + campaigns.link_mode='form' → 収集可
                    INSERT INTO campaigns VALUES (4, 'legacy-form-approval', NULL, 'form');
                    INSERT INTO campaign_targets VALUES (4, '3333333333', 1, NULL);
                    -- 5: 混在。content_no=1 が form、=2 が link。承認済み。対象が =2 を指す行は不可
                    --    (content 単位の判定。campaigns 単位にフォールバックしない)
                    INSERT INTO campaigns VALUES (5, 'mixed-approval', NULL, 'form');
                    INSERT INTO campaign_contents VALUES (5, 1, 'form');
                    INSERT INTO campaign_contents VALUES (5, 2, 'link');
                    INSERT INTO campaign_targets VALUES (5, '4444444444', 1, 2);
                    INSERT INTO campaign_targets VALUES (5, '5555555555', 1, 1);
                """)
                conn.commit()
            finally:
                conn.close()

            approved = create_beacon_files.is_credential_capture_approved
            self.assertTrue(approved("0123456789", 1, db_path), 'form+承認+auth_flag=収集可')
            self.assertFalse(approved("0123456789", 2, db_path), 'auth_flag 不一致は不可')
            self.assertFalse(approved("1111111111", 1, db_path), '承認なしは不可')
            self.assertFalse(approved("2222222222", 1, db_path), 'link形式は承認があっても不可')
            self.assertTrue(approved("3333333333", 1, db_path), '旧経路のform+承認は収集可')
            self.assertFalse(approved("4444444444", 1, db_path), '混在で link content(content_no=2)を指す行は不可')
            self.assertTrue(approved("5555555555", 1, db_path), '混在で form content(content_no=1)を指す行は可')
            self.assertFalse(approved("9999999999", 1, db_path), '未知trackingは不可')
            self.assertTrue(create_beacon_files.capture_enabled_for_page(
                "https://example.test", "0123456789", 1, db_path), 'https+form+承認=収集可')
            self.assertFalse(create_beacon_files.capture_enabled_for_page(
                "http://example.test", "0123456789", 1, db_path), 'http は不可(平文送信を防ぐ)')



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

            errors = create_beacon_files.validate_generated_artifacts(rows, root, root)

        self.assertEqual(errors, [])


class SuppressPrefillEmailTest(unittest.TestCase):
    def test_should_treat_truthy_values_as_suppress(self) -> None:
        for value in ("1", "1.0", "True", "true", " 1 "):
            with self.subTest(value=value):
                self.assertTrue(
                    create_beacon_files.is_suppress_prefill_email(value)
                )

    def test_should_treat_empty_and_zero_as_not_suppress(self) -> None:
        # 旧CSV(列なし)は '' として渡る → 従来通りメール事前入力。
        for value in ("", "0", "nan", "false", None):
            with self.subTest(value=value):
                self.assertFalse(
                    create_beacon_files.is_suppress_prefill_email(value)
                )

    def test_should_return_empty_string_when_suppressed(self) -> None:
        self.assertEqual(
            create_beacon_files.build_auth_email_value("user@example.com", True),
            "",
        )

    def test_should_return_escaped_email_when_not_suppressed(self) -> None:
        # quote=True で属性値として安全にエスケープされること(格納型XSS対策)。
        out = create_beacon_files.build_auth_email_value('a"><b@example.com', False)
        self.assertNotIn('"', out)
        self.assertIn("&quot;", out)

    def test_master_html_email_value_becomes_empty_when_suppressed(self) -> None:
        # 実際の master3.html を模した value="#$6$#" が空になることを確認。
        master = '<input type="email" name="email" value="#$6$#" placeholder="">'
        suppressed = master.replace(
            "#$6$#",
            create_beacon_files.build_auth_email_value("user@example.com", True),
        )
        self.assertIn('value=""', suppressed)
        not_suppressed = master.replace(
            "#$6$#",
            create_beacon_files.build_auth_email_value("user@example.com", False),
        )
        self.assertIn('value="user@example.com"', not_suppressed)


class ResolveQrExtensionTest(unittest.TestCase):
    def test_should_map_document_identifiers_to_formats(self) -> None:
        cases = {
            "qr_docx": ("doc", "docx"),
            "qr_pdf": ("doc", "pdf"),
            "qr_html": ("doc", "html"),
            "qr_xlsx": ("doc", "xlsx"),
            "qr_pptx": ("doc", "pptx"),
        }
        for key, expected in cases.items():
            with self.subTest(key=key):
                self.assertEqual(
                    create_beacon_files.resolve_qr_extension(key), expected
                )

    def test_should_fallback_to_png_for_plain_qr_and_unknown(self) -> None:
        for key in ("qr", "exe", "", "doc"):
            with self.subTest(key=key):
                self.assertEqual(
                    create_beacon_files.resolve_qr_extension(key), ("png", None)
                )


class SanitizeAttachmentPrefixTest(unittest.TestCase):
    """添付ファイル名接頭辞のサニタイズ(最終防波堤)。

    この接頭辞は `{prefix}{tracking_id}.{ext}` のファイル名になり、send_email.py が
    その basename を Content-Disposition ヘッダに載せる。PHP をすり抜けた危険な値を
    ここで確実に無害化する。
    """

    def test_should_keep_normal_prefix(self) -> None:
        self.assertEqual(
            create_beacon_files.sanitize_attachment_prefix("添付資料-"), "添付資料-"
        )

    def test_should_fallback_to_kunren_when_empty(self) -> None:
        for value in ("", "   ", None, "nan"):
            with self.subTest(value=value):
                self.assertEqual(
                    create_beacon_files.sanitize_attachment_prefix(value), "kunren"
                )

    def test_should_strip_path_separators(self) -> None:
        # パス区切りを除去して Attachment ディレクトリ外に出させない
        self.assertNotIn("/", create_beacon_files.sanitize_attachment_prefix("a/b/c"))
        self.assertNotIn("\\", create_beacon_files.sanitize_attachment_prefix("a\\b"))

    def test_should_remove_parent_directory(self) -> None:
        self.assertNotIn("..", create_beacon_files.sanitize_attachment_prefix("../etc"))

    def test_should_strip_control_and_newline(self) -> None:
        # CR/LF はヘッダインジェクションになるため必ず除去
        result = create_beacon_files.sanitize_attachment_prefix("a\r\nb\x00c")
        self.assertNotIn("\r", result)
        self.assertNotIn("\n", result)
        self.assertNotIn("\x00", result)

    def test_should_remove_reserved_symbols(self) -> None:
        result = create_beacon_files.sanitize_attachment_prefix('a:b*c?"<>|d')
        for ch in ':*?"<>|':
            self.assertNotIn(ch, result)

    def test_should_cap_length(self) -> None:
        self.assertEqual(len(create_beacon_files.sanitize_attachment_prefix("あ" * 50)), 40)

    def test_pure_traversal_falls_back_to_kunren(self) -> None:
        # 除去後に空になったら kunren にフォールバック
        self.assertEqual(
            create_beacon_files.sanitize_attachment_prefix("../"), "kunren"
        )


if __name__ == "__main__":
    unittest.main()
