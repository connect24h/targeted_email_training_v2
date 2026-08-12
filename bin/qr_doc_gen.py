#!/usr/bin/env python3
"""QR埋め込み文書生成 — 単体検証用。
本文テキストに即した docx / pdf / html を生成し、QR画像を埋め込む。
create_beacon_files.py に統合する前に、3形式すべてが生成でき QR が読めることを確かめる。
"""
import io
import os
import base64
import qrcode


def _qr_png_bytes(url: str) -> bytes:
    """追跡URLをエンコードした QR の PNG バイト列を返す。"""
    qr = qrcode.QRCode(
        version=1,
        error_correction=qrcode.constants.ERROR_CORRECT_L,
        box_size=10,
        border=4,
    )
    qr.add_data(url)
    qr.make(fit=True)
    img = qr.make_image(fill_color="black", back_color="white")
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()


def generate_qr_document(fmt: str, body_text: str, tracking_url: str, out_path: str) -> str:
    """本文 body_text に QR を埋め込んだ fmt 形式の文書を out_path に書き出す。

    fmt: 'docx' | 'pdf' | 'html' | 'xlsx' | 'pptx'
    2-a: body_text は honbun.csv の本文をそのまま流し込む(プレースホルダ置換は呼び出し側で済ませる)。
    """
    fmt = fmt.lower()
    qr_png = _qr_png_bytes(tracking_url)

    if fmt == "docx":
        from docx import Document
        from docx.shared import Mm
        doc = Document()
        # 本文は改行ごとに段落へ。空行も維持して元の体裁を保つ。
        for line in body_text.split("\n"):
            doc.add_paragraph(line)
        doc.add_paragraph("")  # 本文とQRの間に一行あける
        qr_tmp = out_path + ".qr.png"
        with open(qr_tmp, "wb") as f:
            f.write(qr_png)
        try:
            doc.add_picture(qr_tmp, width=Mm(40))
        finally:
            os.remove(qr_tmp)
        doc.save(out_path)

    elif fmt == "pdf":
        from reportlab.lib.pagesizes import A4
        from reportlab.lib.units import mm
        from reportlab.pdfgen import canvas
        from reportlab.lib.utils import ImageReader
        from reportlab.pdfbase import pdfmetrics
        from reportlab.pdfbase.cidfonts import UnicodeCIDFont
        # 日本語フォント(reportlab同梱のCID)。埋め込み不要で日本語が出る。
        pdfmetrics.registerFont(UnicodeCIDFont("HeiseiKakuGo-W5"))
        c = canvas.Canvas(out_path, pagesize=A4)
        width, height = A4
        c.setFont("HeiseiKakuGo-W5", 11)
        y = height - 25 * mm
        for line in body_text.split("\n"):
            c.drawString(20 * mm, y, line)
            y -= 7 * mm
            if y < 60 * mm:  # ページ下端でQRを置く余白を確保して打ち切り
                break
        c.drawImage(ImageReader(io.BytesIO(qr_png)), 20 * mm, 20 * mm,
                    width=35 * mm, height=35 * mm)
        c.showPage()
        c.save()

    elif fmt == "html":
        from jinja2 import Template
        b64 = base64.b64encode(qr_png).decode("ascii")
        tpl = Template(
            "<!DOCTYPE html><html lang=\"ja\"><head><meta charset=\"utf-8\">"
            "<style>body{font-family:sans-serif;font-size:15px;line-height:1.7;color:#333;"
            "max-width:640px;margin:2em auto;padding:0 1em}"
            ".qr{margin-top:1.5em}</style></head><body>"
            "<div class=\"body\">{{ body_html }}</div>"
            "<div class=\"qr\"><img src=\"data:image/png;base64,{{ qr }}\" "
            "alt=\"QR\" width=\"180\"></div></body></html>"
        )
        # 本文の改行を <br> に。HTMLエスケープは jinja2 の autoescape ではなく明示処理。
        from markupsafe import escape
        body_html = str(escape(body_text)).replace("\n", "<br>\n")
        html = tpl.render(body_html=body_html, qr=b64)
        with open(out_path, "w", encoding="utf-8") as f:
            f.write(html)

    elif fmt == "xlsx":
        from openpyxl import Workbook
        from openpyxl.drawing.image import Image as XLImage
        wb = Workbook()
        ws = wb.active
        # 本文は行ごとにA列へ。空行も1セルとして維持し元の体裁を保つ。
        for i, line in enumerate(body_text.split("\n"), start=1):
            ws.cell(row=i, column=1, value=line)
        # QRは本文の末尾から2行下に配置。openpyxl は PNG ファイルパスを要求するため一時保存する。
        qr_tmp = out_path + ".qr.png"
        with open(qr_tmp, "wb") as f:
            f.write(qr_png)
        try:
            img = XLImage(qr_tmp)
            img.width = 150
            img.height = 150
            anchor_row = body_text.count("\n") + 3
            ws.add_image(img, f"A{anchor_row}")
            wb.save(out_path)
        finally:
            os.remove(qr_tmp)

    elif fmt == "pptx":
        from pptx import Presentation
        from pptx.util import Inches, Pt
        prs = Presentation()
        # 白紙レイアウト(index=6)にテキストボックスとQRを直接置く。
        slide = prs.slides.add_slide(prs.slide_layouts[6])
        tb = slide.shapes.add_textbox(Inches(0.5), Inches(0.4), Inches(6.0), Inches(4.5))
        tf = tb.text_frame
        tf.word_wrap = True
        lines = body_text.split("\n")
        tf.text = lines[0] if lines else ""
        for line in lines[1:]:
            p = tf.add_paragraph()
            p.text = line
        for p in tf.paragraphs:
            for run in p.runs:
                run.font.size = Pt(14)
        qr_tmp = out_path + ".qr.png"
        with open(qr_tmp, "wb") as f:
            f.write(qr_png)
        try:
            slide.shapes.add_picture(qr_tmp, Inches(7.0), Inches(2.0),
                                     width=Inches(2.0), height=Inches(2.0))
            prs.save(out_path)
        finally:
            os.remove(qr_tmp)

    else:
        raise ValueError(f"unsupported fmt: {fmt}")

    return out_path


if __name__ == "__main__":
    body = ("#$2$#さん\n\nお疲れさまです。人事部です。\n"
            "下記のQRコードから、マイナンバー情報の確認をお願いします。\n"
            "期限は本日中です。\n\n※本メールは標的型攻撃メール訓練です。")
    url = "http://85.131.251.224/link-1234567890.html"
    outdir = "/tmp/claude-0/-root/3306ab7d-effd-4e4d-9e11-7acd4c62bf69/scratchpad"
    for fmt in ("docx", "pdf", "html", "xlsx", "pptx"):
        p = os.path.join(outdir, f"qrdoc-test.{fmt}")
        generate_qr_document(fmt, body, url, p)
        sz = os.path.getsize(p)
        print(f"{fmt}: {p} ({sz} bytes) OK")
