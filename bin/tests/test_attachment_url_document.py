"""本文URL型(link_mode='attachment_url')の添付生成ユニットテスト(2026-11-03)。

対象:
- create_beacon_files.resolve_url_extension(): 'url_pdf' だけを本文URL型と判定し、
  QR識別子(qr_pdf 等)・通常拡張子(html 等)は None（本文URL型でない）を返すこと。
- qr_doc_gen.generate_url_document(): PDF にクリック可能なリンク注釈(/Link + URI)を
  埋め込み、PDF 以外の fmt は ValueError で弾くこと(本文URL型は常にPDF)。
"""
import sys
import tempfile
import unittest
from pathlib import Path


BIN_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BIN_DIR))

import create_beacon_files  # noqa: E402
import qr_doc_gen  # noqa: E402


class ResolveUrlExtensionTest(unittest.TestCase):
    def test_url_pdf_is_recognized_as_pdf(self) -> None:
        self.assertEqual(create_beacon_files.resolve_url_extension('url_pdf'), 'pdf')
        # 大文字・前後空白も正規化して判定する。
        self.assertEqual(create_beacon_files.resolve_url_extension('  URL_PDF '), 'pdf')

    def test_qr_and_plain_extensions_are_not_url_type(self) -> None:
        # QR識別子や通常拡張子は本文URL型ではない(=None)。QR/従来添付の経路へ進ませる。
        for ext in ('qr', 'qr_pdf', 'qr_docx', 'html', 'pdf', 'docx', 'exe', '', 'nan'):
            self.assertIsNone(create_beacon_files.resolve_url_extension(ext), ext)


class GenerateUrlDocumentTest(unittest.TestCase):
    def test_pdf_contains_clickable_uri_annotation(self) -> None:
        url = "http://85.131.251.224/link-1234567890.html"
        body = "山田さん\n\nお疲れさまです。\n下記より確認をお願いします。"
        with tempfile.TemporaryDirectory() as d:
            out = str(Path(d) / "urldoc.pdf")
            ret = qr_doc_gen.generate_url_document('pdf', body, url, out)
            self.assertEqual(ret, out)
            data = Path(out).read_bytes()
            # クリック可能リンク: /Link 注釈と /URI(埋め込みURL)が存在すること。
            self.assertIn(b'/Link', data)
            self.assertIn(url.encode('ascii'), data)
            self.assertGreater(len(data), 0)

    def test_non_pdf_format_is_rejected(self) -> None:
        # 本文URL型は常に PDF。docx 等は生成しない(ValueError)。
        for fmt in ('docx', 'html', 'xlsx', 'pptx', 'png'):
            with self.assertRaises(ValueError):
                qr_doc_gen.generate_url_document(fmt, "body", "http://x/link-0000000000.html", "/tmp/should-not-create")


if __name__ == '__main__':
    unittest.main()
