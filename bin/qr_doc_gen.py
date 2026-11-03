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


def generate_url_document(fmt: str, body_text: str, tracking_url: str, out_path: str,
                          url_marker: str = None) -> str:
    """本文 body_text に、クリック可能な追跡URLリンクを埋め込んだ PDF を out_path に書き出す。

    QR型(generate_qr_document)が QR 画像を置くのに対し、本関数は tracking_url への
    クリック可能なハイパーリンク(下線付きの可視URL)を本文に置く。受信者がPDFを開いて
    リンクを踏む→link-{tid}.html(偽ログイン)へ、という導線を作る(add_url型)。

    url_marker: 本文中のこの文字列の位置にリンクを差し込む(本文テンプレの #$1$# 相当)。
                指定があり本文中に見つかればその位置へ、見つからない/未指定なら本文末尾に
                追記する(後方互換)。
    fmt: 'pdf' のみ対応(本文URL型の添付は常にPDF)。他は ValueError。
    tracking_url: 埋め込むリンク先(通常 {base}/link-{tracking_id}.html)。
    """
    fmt = fmt.lower()
    if fmt != "pdf":
        raise ValueError(f"generate_url_document は pdf のみ対応: {fmt}")

    from reportlab.lib.pagesizes import A4
    from reportlab.lib.units import mm
    from reportlab.pdfgen import canvas
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.cidfonts import UnicodeCIDFont
    # 日本語フォント(reportlab同梱のCID)。埋め込み不要で日本語が出る。
    pdfmetrics.registerFont(UnicodeCIDFont("HeiseiKakuGo-W5"))
    c = canvas.Canvas(out_path, pagesize=A4)
    width, height = A4
    font_name, font_size = "HeiseiKakuGo-W5", 11
    left = 20 * mm
    line_gap = 7 * mm
    top_y = height - 25 * mm
    bottom_limit = 20 * mm

    def new_page_if_needed(yy):
        if yy < bottom_limit:
            c.showPage()
            c.setFont(font_name, font_size)
            return top_y
        return yy

    def draw_clickable_url(x, yy):
        """(x, yy) から可視の tracking_url を青・下線・外部リンク付きで描き、右端のxを返す。"""
        c.setFillColorRGB(0.0, 0.0, 0.8)
        c.drawString(x, yy, tracking_url)
        w = c.stringWidth(tracking_url, font_name, font_size)
        c.setStrokeColorRGB(0.0, 0.0, 0.8)
        c.line(x, yy - 1.2, x + w, yy - 1.2)
        c.linkURL(tracking_url, (x, yy - 3, x + w, yy + font_size), relative=0)
        c.setFillColorRGB(0.0, 0.0, 0.0)
        return x + w

    c.setFont(font_name, font_size)
    y = top_y
    placed = False
    for line in body_text.split("\n"):
        y = new_page_if_needed(y)
        if url_marker and url_marker in line:
            # #$1$# の位置: マーカー前テキスト → クリック可能URL → マーカー後テキスト を同じ行に描く。
            before, after = line.split(url_marker, 1)
            x = left
            if before:
                c.setFillColorRGB(0.0, 0.0, 0.0)
                c.drawString(x, y, before)
                x += c.stringWidth(before, font_name, font_size)
            x = draw_clickable_url(x, y)
            if after:
                c.setFillColorRGB(0.0, 0.0, 0.0)
                c.drawString(x, y, after)
            placed = True
        else:
            c.setFillColorRGB(0.0, 0.0, 0.0)
            c.drawString(left, y, line)
        y -= line_gap
    if not placed:
        # マーカーが無い本文は、一行あけて末尾にリンクを追記する(後方互換)。
        y = new_page_if_needed(y - line_gap)
        draw_clickable_url(left, y)
    c.showPage()
    c.save()
    return out_path


if __name__ == "__main__":
    import tempfile
    body = ("#$2$#さん\n\nお疲れさまです。人事部です。\n"
            "下記のQRコードから、マイナンバー情報の確認をお願いします。\n"
            "期限は本日中です。\n\n※本メールは標的型攻撃メール訓練です。")
    url = "http://85.131.251.224/link-1234567890.html"
    outdir = tempfile.mkdtemp(prefix="qrdoc-test-")
    for fmt in ("docx", "pdf", "html", "xlsx", "pptx"):
        p = os.path.join(outdir, f"qrdoc-test.{fmt}")
        generate_qr_document(fmt, body, url, p)
        sz = os.path.getsize(p)
        print(f"qr {fmt}: {p} ({sz} bytes) OK")
    # 本文URL型(add_url): クリック可能リンク埋め込みPDF(末尾追記=マーカー無し)。
    up = os.path.join(outdir, "urldoc-test.pdf")
    generate_url_document("pdf", body, url, up)
    print(f"url pdf (末尾): {up} ({os.path.getsize(up)} bytes) OK")
    # 本文URL型(マーカー位置に差し込み)。#$1$# 相当のマーカーをリンクに置換する。
    marker = "@@TET2_URL_LINK@@"
    body_with_marker = ("#$2$#さん\n\n連絡先情報の確認をお願いします。\n\n"
                        + marker + "\n\nご多忙のところ恐縮です。")
    up2 = os.path.join(outdir, "urldoc-marker-test.pdf")
    generate_url_document("pdf", body_with_marker, url, up2, url_marker=marker)
    print(f"url pdf (マーカー): {up2} ({os.path.getsize(up2)} bytes) OK")
