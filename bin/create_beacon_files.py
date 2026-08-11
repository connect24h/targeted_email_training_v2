#!/usr/bin/env python3
"""
ビーコンファイル作成プログラム
"""

import pandas as pd
import os
import sys
import shutil
import zipfile
import logging
import base64
import configparser
import html
import qrcode
from datetime import datetime
from pathlib import Path

# QR埋め込み文書生成（同ディレクトリに配置。ロジックは qr_doc_gen.py 側を正とし、ここでは import のみ行う）。
from qr_doc_gen import generate_qr_document

import sqlite3


def resolve_tenant_reveal(tracking_id):
    """tracking_id(=乱数列)から所属テナントの reveal.html パスを返す。無ければ None。

    (2026-08-06) テナント別の種明かし対応。認証なし(auth_flag=0)の link ページは
    生成時に種明かしを直接埋め込むため、共通 master.html ではなく、そのテナントの
    reveal.html があれば優先する(例: Goldwin 専用の種明かし)。
    DB が読めない・reveal.html が無い・例外は全て None を返し、呼び出し側で
    共通 master.html にフォールバックさせる(訓練を止めない・常に安全側)。
    training_log.php(認証あり経路)と同じ解決ロジックを Python 側にも置く。
    """
    if not tracking_id or tracking_id == 'unknown':
        return None
    try:
        db = '/opt/training/tet2-db/tet2.sqlite'
        if not os.path.exists(db):
            return None
        conn = sqlite3.connect(f'file:{db}?mode=ro', uri=True)
        try:
            row = conn.execute(
                'SELECT t.data_dir FROM campaign_targets ct '
                'JOIN campaigns c ON c.id = ct.campaign_id '
                'JOIN tenants   t ON t.id = c.tenant_id '
                'WHERE ct.tracking_id = ? LIMIT 1',
                (tracking_id,)
            ).fetchone()
        finally:
            conn.close()
        if row and row[0]:
            reveal = os.path.join(str(row[0]).rstrip('/'), 'reveal.html')
            if os.path.isfile(reveal) and os.access(reveal, os.R_OK):
                return reveal
    except Exception:
        return None
    return None

# 標準出力のバッファリングを無効化
sys.stdout.reconfigure(line_buffering=True)

# QR型添付ファイルの拡張子識別子。Attachment.csv の「拡張子」列に書かれる値と一致させる
# （PipelineRunner.php 側もこの識別子でマップするため、変更する場合は両方を揃えること）。
#   'qr'      : 従来の生QR画像PNG（後方互換のデフォルト）
#   'qr_docx' : 本文+QRを埋め込んだ docx
#   'qr_pdf'  : 本文+QRを埋め込んだ pdf
#   'qr_html' : 本文+QRを埋め込んだ html
QR_DOCUMENT_FORMATS = {
    'qr_docx': 'docx',
    'qr_pdf': 'pdf',
    'qr_html': 'html',
}

WEB_ROOT = Path("/var/www/html")
ATTACHMENT_ROOT = Path("/opt/training/bin/Attachment")


def _has_csv_value(value):
    return not pd.isna(value) and str(value).strip() not in ("", "nan")


def validate_generated_artifacts(list_df, web_root=WEB_ROOT, attachment_root=ATTACHMENT_ROOT):
    """送信に必要な生成物が全行分揃っていることを検証する。"""
    errors = []
    root = Path(web_root)
    for index, row in list_df.iterrows():
        tracking_id = str(row.get('乱数列', '')).strip().zfill(10)
        for label, path in (
            ('ビーコン', root / f"kunren-beacon-{tracking_id}.png"),
            ('リンクページ', root / f"link-{tracking_id}.html"),
        ):
            if not path.is_file() or path.stat().st_size == 0 or not os.access(path, os.R_OK):
                errors.append(f"行 {index + 1}: {label}が未生成または読取不可: {path}")

        if not _has_csv_value(row.get('添付ファイル番号', '')):
            continue
        attachment_value = row.get('添付ファイル', '')
        if not _has_csv_value(attachment_value):
            errors.append(f"行 {index + 1}: 必須添付のパスが空です")
            continue
        attachment_path = Path(str(attachment_value).strip())
        try:
            resolved_attachment = attachment_path.resolve()
            resolved_attachment.relative_to(Path(attachment_root).resolve())
        except (OSError, ValueError):
            errors.append(f"行 {index + 1}: 必須添付が管理外のpathです: {attachment_path}")
            continue
        if (not resolved_attachment.is_file() or resolved_attachment.stat().st_size == 0
                or not os.access(resolved_attachment, os.R_OK)):
            errors.append(f"行 {index + 1}: 必須添付が未生成、空、または読取不可: {attachment_path}")
    return errors


def resolve_qr_extension(extension):
    """Attachment.csv の拡張子文字列から QR添付の種別を判定する。

    戻り値: ('png', None) 従来の生QR画像PNG（既定・フォールバック）
            ('doc', fmt)   本文+QRを埋め込んだ文書。fmt は 'docx'|'pdf'|'html'
    """
    key = str(extension).strip().lower()
    if key in QR_DOCUMENT_FORMATS:
        return ('doc', QR_DOCUMENT_FORMATS[key])
    # 'qr' 含む未知の識別子は全て従来PNGにフォールバック（後方互換を最優先）。
    return ('png', None)


def get_body_template(body_templates_df, template_no):
    """本文定型文の取得（send_email.py の get_body_template を踏襲）。"""
    try:
        template_no_int = int(float(template_no))
        mask = body_templates_df['項番'] == template_no_int
        if mask.any():
            return body_templates_df.loc[mask, '本文定型文'].iloc[0]
        return "本文テンプレートが見つかりません"
    except Exception:
        return "本文取得エラー"


def replace_placeholders(text, row):
    """本文差し込み処理（send_email.py の replace_placeholders を踏襲）。"""
    if pd.isna(text):
        return ""

    placeholder_columns = {
        '#$1$#': '本文差し込み1 #$1$#',
        '#$2$#': '本文差し込み2 #$2$#',
        '#$3$#': '本文差し込み3 #$3$#',
        '#$4$#': '本文差し込み4 #$4$#',
    }

    for placeholder, column_name in placeholder_columns.items():
        value = row.get(column_name, '')
        if pd.isna(value) or value == '':
            text = text.replace(placeholder, '')
        else:
            text = text.replace(placeholder, str(value))

    return text

def setup_logging():
    """ログ設定。

    operation.log は www-data(このスクリプト)と training(send_email.py)が共有する固定
    ファイル。異なるユーザーが交互に append するため、group(www-data)書き込みを保てるよう
    umask 0002 を設定してから開き、新規作成時のモードを 664 にする。開けない場合でも
    ビーコン生成本体を止めないよう握りつぶす。
    (2026-08 に operation.log の所有者/権限がずれ、他方のプロセスが書けず送信が全滅した
     事故の恒久対策。ユーザー一本化(sudo -u training)と併せた多重防御。)
    """
    log_file = "/opt/training/logs/operation.log"

    logger = logging.getLogger(__name__)
    logger.setLevel(logging.INFO)

    try:
        # 新規作成時に group 書き込みを許可(www-data と training が共有するため)。
        old_umask = os.umask(0o002)
        try:
            file_handler = logging.FileHandler(log_file, encoding='utf-8', mode='a')
        finally:
            os.umask(old_umask)
        file_handler.setLevel(logging.INFO)
        file_handler.setFormatter(logging.Formatter('%(asctime)s - [create_beacon_files] - %(levelname)s - %(message)s'))
        logger.addHandler(file_handler)
        # 既存ファイルが group 非書き込みでも、自分が所有者なら 664 に揃える(次回の他ユーザー用)。
        try:
            os.chmod(log_file, 0o664)
        except OSError:
            pass
    except OSError as exc:
        # 共有ログに書けないだけ。ビーコン生成本体は止めない。
        logging.getLogger(__name__).warning(
            "operation.log を開けませんでした(%s)。動作ログ無しで継続します。", exc
        )
    # コンソールハンドラーは追加しない

    return logger


def read_list_csv(path):
    """tracking IDを数値へ変換せず、先頭ゼロを保持して読む。"""
    return pd.read_csv(path, dtype=str)


def create_beacon_files(data_dir='/opt/training/bin/data', beacon_base_override=None):
    """ビーコンファイルの作成。

    beacon_base_override が指定されればビーコンURLベースにそれを最優先で使う
    (キャンペーンごとの beacon_base を DB から渡す用)。未指定なら config.ini。
    """
    logger = setup_logging()

    print(f"\n{'='*60}", flush=True)
    print("ビーコンファイル作成プログラム開始", flush=True)
    print(f"{'='*60}", flush=True)
    logger.info("ビーコンファイル作成プログラム開始")

    # CSVファイル読み込み
    list_csv_path = os.path.join(data_dir, "list.csv")
    attachment_csv_path = os.path.join(data_dir, "Attachment.csv")
    honbun_csv_path = os.path.join(data_dir, "honbun.csv")

    try:
        list_df = read_list_csv(list_csv_path)
        attachment_df = pd.read_csv(attachment_csv_path)
        # honbun.csv はQR文書型（qr_docx/qr_pdf/qr_html）で本文をそのまま流し込むために使う。
        # 既存の生QR型(extension=='qr')やその他添付型では未使用のため、無くても致命的ではない。
        if os.path.exists(honbun_csv_path):
            honbun_df = pd.read_csv(honbun_csv_path)
        else:
            honbun_df = pd.DataFrame(columns=['項番', '本文定型文'])
            logger.info(f"honbun.csvが見つかりません（QR文書型は使用不可）: {honbun_csv_path}")
        print(f"\n[OK] CSVファイル読み込み完了", flush=True)
        print(f"     list.csv: {len(list_df)}行", flush=True)
        print(f"     Attachment.csv: {len(attachment_df)}行", flush=True)
        logger.info(f"list.csv読み込み: {len(list_df)}行")
        logger.info(f"Attachment.csv読み込み: {len(attachment_df)}行")
        logger.info(f"CSVファイル読み込み完了 - list.csv: {len(list_df)}行, Attachment.csv: {len(attachment_df)}行")
    except Exception as e:
        error_msg = f"[ERROR] CSVファイル読み込みエラー: {str(e)}"
        print(error_msg)
        logger.error(error_msg)
        return 1
    
    # ビーコンマスターファイルパス
    # ビーコンマスターファイルとHTMLマスターファイルは固定パス
    beacon_master = "/opt/training/bin/__BeaconMst.png"

    if (not os.path.isfile(beacon_master) or not os.access(beacon_master, os.R_OK)):
        error_msg = f"[ERROR] ビーコンマスターファイルが未配置または読取不可: {beacon_master}"
        print(error_msg, flush=True)
        logger.error(error_msg)
        return 1

    # HTMLマスターファイルの読み込み関数
    def load_html_master(auth_flag):
        """認証フラグに応じたHTMLマスターファイルを読み込む"""
        # 認証フラグに応じてHTMLファイルを選択
        html_files = {
            0: "/opt/training/bin/master.html",   # 通常
            1: "/opt/training/bin/master2.html",  # Box
            2: "/opt/training/bin/master3.html",  # MS365
            3: "/opt/training/bin/master4.html",  # デジタルアーツ
            4: "/opt/training/bin/master5.html",  # MS365(メールのみ)
        }

        # デフォルトはmaster.html
        if pd.isna(auth_flag):
            auth_flag = 0

        try:
            auth_flag_int = int(float(auth_flag))
        except (ValueError, TypeError):
            auth_flag_int = 0

        html_master = html_files.get(auth_flag_int, html_files[0])

        if not os.path.exists(html_master):
            logger.info(f"警告: HTMLマスターファイルが見つかりません: {html_master}")
            logger.info(f"       デフォルトのmaster.htmlを使用します")
            html_master = html_files[0]

        # マスターHTMLファイルの読み込み（文字エンコーディング自動検出）
        try:
            with open(html_master, 'r', encoding='utf-8') as f:
                return f.read(), html_master
        except UnicodeDecodeError:
            # UTF-8で読めない場合はShift_JISで試行
            try:
                with open(html_master, 'r', encoding='shift_jis') as f:
                    return f.read(), html_master
            except UnicodeDecodeError:
                # それでもダメな場合はcp932で試行
                with open(html_master, 'r', encoding='cp932') as f:
                    return f.read(), html_master
    
    # 各行を処理
    total_rows = len(list_df)
    print(f"\n処理開始: 全{total_rows}件のデータを処理します", flush=True)

    for index, row in list_df.iterrows():
        random_value = str(row.get('乱数列', '')).zfill(10)  # 10桁ゼロパディング
        attachment_no = row.get('添付ファイル番号', '')
        auth_flag = row.get('認証フラグ', 0)  # 認証フラグ取得（デフォルト0）
        to_email = str(row.get('送信先情報', ''))  # 送信先メールアドレス取得

        if not random_value:
            print(f"[WARN] 行 {index+1}: 乱数列が空のためスキップ", flush=True)
            logger.info(f"行 {index+1}: 乱数列が空のためスキップ")
            continue

        print(f"\n{'='*60}", flush=True)
        print(f"処理中: {index+1}/{total_rows} 件目", flush=True)
        print(f"  乱数列: {random_value}", flush=True)
        print(f"  送信先: {to_email}", flush=True)
        print(f"  添付ファイル番号: {attachment_no}", flush=True)
        print(f"  認証フラグ: {auth_flag}", flush=True)

        logger.info(f"\n=== 行 {index+1} 処理開始 ===")
        logger.info(f"乱数列: {random_value}")
        logger.info(f"送信先: {to_email}")
        logger.info(f"添付ファイル番号: {attachment_no}")
        logger.info(f"認証フラグ: {auth_flag}")
        
        # 1. ビーコンPNGファイルのコピー
        beacon_filename = f"kunren-beacon-{random_value}.png"
        beacon_dest_path = f"/var/www/html/{beacon_filename}"
        
        try:
            # 既存ファイルがあれば削除
            if os.path.exists(beacon_dest_path):
                os.remove(beacon_dest_path)
            shutil.copy2(beacon_master, beacon_dest_path)
            # パーミッション設定
            os.chmod(beacon_dest_path, 0o644)
            print(f"  [OK] ビーコンファイル作成: {beacon_filename}", flush=True)
            logger.info(f"ビーコンファイル作成: {beacon_dest_path}")
        except Exception as e:
            print(f"  [ERROR] ビーコンファイルコピーエラー: {str(e)}", flush=True)
            logger.info(f"ビーコンファイルコピーエラー: {str(e)}")
            continue
        
        # 2. HTMLファイルの作成（認証フラグに応じたマスターファイルを使用）
        try:
            master_html_content, html_master_path = load_html_master(auth_flag)
            # 認証なし(auth_flag=0)は link ページに種明かしを直接埋め込む。
            # このときテナント別 reveal.html があれば共通 master.html より優先する。
            # 認証あり(1/2/3)は認証画面を出し、種明かしは training_log.php 経路(実装済み)が
            # テナント別 reveal を返すため、ここでは触らない(二重対応を避ける)。
            try:
                af_int = int(float(auth_flag)) if str(auth_flag).strip() not in ('', 'nan') else 0
            except (ValueError, TypeError):
                af_int = 0
            if af_int == 0:
                reveal_path = resolve_tenant_reveal(random_value)
                if reveal_path:
                    with open(reveal_path, 'r', encoding='utf-8') as rf:
                        master_html_content = rf.read()
                    html_master_path = reveal_path
                    print(f"  [FILE] テナント別種明かしを使用: {reveal_path}", flush=True)
                    logger.info(f"テナント別種明かしを使用: {reveal_path}")
            print(f"  [FILE] 使用するHTMLマスター: {os.path.basename(html_master_path)}", flush=True)
            logger.info(f"使用するHTMLマスター: {os.path.basename(html_master_path)}")
        except Exception as e:
            print(f"  [ERROR] HTMLマスターファイル読み込みエラー: {str(e)}", flush=True)
            logger.info(f"HTMLマスターファイル読み込みエラー: {str(e)}")
            continue

        # ビーコンURLベースの決定。優先順位: 引数(DBのbeacon_base) > config.ini > 既定。
        # 従来は config.ini 固定で、UI/DBで filesend.cojp.online に切り替えても QR と
        # link-*.html だけ古い http://85.131.251.224 のままになる不整合があった
        # (メール本文リンクは PHP 側が DB beacon_base を使うため経路が分かれていた)。
        if beacon_base_override:
            beacon_base = beacon_base_override.rstrip('/')
        else:
            cfg = configparser.ConfigParser()
            cfg_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'config.ini')
            beacon_base = 'http://85.131.251.224'
            if os.path.exists(cfg_path):
                cfg.read(cfg_path)
                beacon_base = cfg.get('server', 'beacon_url_base', fallback=beacon_base).rstrip('/')

        # プレースホルダーの置換
        # #$5$#(random_value)は10桁ゼロ埋めの英数字なのでエスケープ不要。
        # #$6$#(to_email)は master*.html 内で value="#$6$#" のHTML属性値に埋め込まれるため、
        #  格納型XSS対策として quote=True でエスケープする（差し込む値のみ／テンプレート本体は触らない）。
        html_content = master_html_content.replace('#$5$#', random_value)
        html_content = html_content.replace('#$6$#', html.escape(to_email, quote=True))  # 送信先メールアドレスを置換
        # テンプレート内のハードコードされたURLをconfig値で置換
        html_content = html_content.replace('http://85.131.251.224', beacon_base)

        # /var/www/html/にHTMLファイルを保存
        html_filename = f"link-{random_value}.html"
        html_dest_path = f"/var/www/html/{html_filename}"

        try:
            with open(html_dest_path, 'w', encoding='utf-8') as f:
                f.write(html_content)
            print(f"  [OK] リンクページ作成: {html_filename}", flush=True)
            logger.info(f"HTMLファイル作成: {html_dest_path}")
        except Exception as e:
            print(f"  [ERROR] HTMLファイル作成エラー: {str(e)}", flush=True)
            logger.info(f"HTMLファイル作成エラー: {str(e)}")
            continue
        
        # 3. 添付ファイルの作成
        if pd.notna(attachment_no) and attachment_no != '':
            print(f"  [ATTACH] 添付ファイル処理開始", flush=True)
            try:
                attachment_no_int = int(float(attachment_no))
                matching_attachment = attachment_df[attachment_df['項番'] == attachment_no_int]

                if not matching_attachment.empty:
                    filename = str(matching_attachment.iloc[0].get('添付ファイル名', '')).strip()
                    extension = str(matching_attachment.iloc[0].get('拡張子', '')).strip('",').strip()
                    zip_flag = matching_attachment.iloc[0].get('zipフラグ', 0)

                    # ファイル名が空または'nan'の場合は添付ファイルを作成しない
                    if not filename or filename.lower() == 'nan' or pd.isna(filename):
                        print(f"  [WARN] 添付ファイル名が無効のため、スキップ", flush=True)
                        logger.info(f"行 {index+1}: 添付ファイル名が無効のため、添付ファイル作成をスキップ")
                        continue

                    if filename and extension:
                        # 拡張子のクリーンアップ（先頭のピリオドを除去）
                        if extension.startswith('.'):
                            extension = extension[1:]

                        # QR型の処理（拡張子が 'qr' または 'qr_docx'/'qr_pdf'/'qr_html' の場合）
                        # 判定は resolve_qr_extension() に集約。未知の識別子は 'qr'(PNG)後方互換にフォールバックする。
                        qr_kind, qr_doc_fmt = resolve_qr_extension(extension)
                        if extension.lower() == 'qr' or extension.lower() in QR_DOCUMENT_FORMATS:
                            print(f"  [QR] QRコード型添付ファイル処理開始（種別: {extension}）", flush=True)
                            try:
                                # 追跡URLの構築
                                tracking_url = f"{beacon_base}/link-{random_value}.html"
                                print(f"     [QR] エンコード対象URL: {tracking_url}", flush=True)
                                logger.info(f"QRコード対象URL: {tracking_url}")

                                if qr_kind == 'doc':
                                    # QR文書型: 本文テンプレをそのまま流し込み、QRを埋め込んだ docx/pdf/html を生成する。
                                    body_template_no = row.get('本文定型文No', 1)
                                    raw_body = get_body_template(honbun_df, body_template_no)
                                    body_text = replace_placeholders(raw_body, row)

                                    doc_filename = f"kunren-qr-{random_value}.{qr_doc_fmt}"
                                    qr_dest_path = f"/opt/training/bin/Attachment/{doc_filename}"

                                    if os.path.exists(qr_dest_path):
                                        os.remove(qr_dest_path)

                                    generate_qr_document(qr_doc_fmt, body_text, tracking_url, qr_dest_path)
                                    os.chmod(qr_dest_path, 0o644)

                                    print(f"     [OK] QR文書作成({qr_doc_fmt}): {doc_filename}", flush=True)
                                    logger.info(f"QR文書作成({qr_doc_fmt}): {qr_dest_path}")
                                else:
                                    # 従来の生QR画像PNG（後方互換）
                                    qr = qrcode.QRCode(
                                        version=1,
                                        error_correction=qrcode.constants.ERROR_CORRECT_L,
                                        box_size=10,
                                        border=4,
                                    )
                                    qr.add_data(tracking_url)
                                    qr.make(fit=True)
                                    qr_img = qr.make_image(fill_color="black", back_color="white")

                                    # QRコードPNG保存
                                    qr_filename = f"kunren-qr-{random_value}.png"
                                    qr_dest_path = f"/opt/training/bin/Attachment/{qr_filename}"

                                    # 既存ファイルがあれば削除
                                    if os.path.exists(qr_dest_path):
                                        os.remove(qr_dest_path)

                                    qr_img.save(qr_dest_path)
                                    os.chmod(qr_dest_path, 0o644)

                                    print(f"     [OK] QRコード画像作成: {qr_filename}", flush=True)
                                    logger.info(f"QRコード画像作成: {qr_dest_path}")

                                # ZIPフラグが1の場合、ZIPファイル化（QR画像/QR文書とも共通ロジック）
                                if float(zip_flag) == 1.0:
                                    qr_zip_filename = f"kunren-qr-{random_value}.zip"
                                    qr_zip_dest_path = f"/opt/training/bin/Attachment/{qr_zip_filename}"

                                    with zipfile.ZipFile(qr_zip_dest_path, 'w', zipfile.ZIP_DEFLATED) as zipf:
                                        zipf.write(qr_dest_path, os.path.basename(qr_dest_path))

                                    # 元のファイルを削除
                                    os.remove(qr_dest_path)
                                    print(f"     [ZIP] ZIP化完了: {qr_zip_filename}", flush=True)
                                    logger.info(f"QRコードZIPファイル作成: {qr_zip_dest_path}")

                                    # list.csvの添付ファイルパスを更新
                                    list_df.at[index, '添付ファイル'] = qr_zip_dest_path
                                else:
                                    # list.csvの添付ファイルパスを更新
                                    list_df.at[index, '添付ファイル'] = qr_dest_path

                            except Exception as e:
                                print(f"     [ERROR] QRコード生成エラー: {str(e)}", flush=True)
                                logger.error(f"QRコード生成エラー: {str(e)}")
                        else:
                            # 従来の添付ファイル処理（HTML等）
                            # 添付ファイル名の作成
                            attachment_filename = f"{filename}{random_value}.{extension}"
                            # Attachmentディレクトリは固定パス
                            attachment_dest_path = f"/opt/training/bin/Attachment/{attachment_filename}"

                            # 添付ファイル作成時は認証フラグを無視してmaster.htmlを使用
                            logger.info(f"添付ファイル作成: 認証フラグに関わらずmaster.htmlを使用")
                            try:
                                with open("/opt/training/bin/master.html", 'r', encoding='utf-8') as f:
                                    attachment_html_content = f.read()
                            except UnicodeDecodeError:
                                try:
                                    with open("/opt/training/bin/master.html", 'r', encoding='shift_jis') as f:
                                        attachment_html_content = f.read()
                                except UnicodeDecodeError:
                                    with open("/opt/training/bin/master.html", 'r', encoding='cp932') as f:
                                        attachment_html_content = f.read()

                            # プレースホルダーの置換（添付ファイル用）
                            # #$6$# は master.html の value="#$6$#" 属性値に埋まるため quote=True でエスケープ。
                            attachment_html_content = attachment_html_content.replace('#$5$#', random_value)
                            attachment_html_content = attachment_html_content.replace('#$6$#', html.escape(to_email, quote=True))

                            # 添付ファイルとして保存
                            try:
                                with open(attachment_dest_path, 'w', encoding='utf-8') as f:
                                    f.write(attachment_html_content)
                                print(f"     [OK] 添付ファイル作成: {attachment_filename}", flush=True)
                                logger.info(f"添付ファイル作成: {attachment_dest_path}")

                                # ZIPフラグが1の場合、ZIPファイル化
                                if float(zip_flag) == 1.0:
                                    zip_filename = f"{filename}{random_value}.zip"
                                    # Attachmentディレクトリは固定パス
                                    zip_dest_path = f"/opt/training/bin/Attachment/{zip_filename}"

                                    with zipfile.ZipFile(zip_dest_path, 'w', zipfile.ZIP_DEFLATED) as zipf:
                                        zipf.write(attachment_dest_path, os.path.basename(attachment_dest_path))

                                    # 元のファイルを削除
                                    os.remove(attachment_dest_path)
                                    print(f"     [ZIP] ZIP化完了: {zip_filename}", flush=True)
                                    logger.info(f"ZIPファイル作成: {zip_dest_path}")

                                    # list.csvの添付ファイルパスを更新
                                    list_df.at[index, '添付ファイル'] = zip_dest_path
                                else:
                                    # list.csvの添付ファイルパスを更新
                                    list_df.at[index, '添付ファイル'] = attachment_dest_path

                            except Exception as e:
                                print(f"     [ERROR] 添付ファイル作成エラー: {str(e)}", flush=True)
                                logger.info(f"添付ファイル作成エラー: {str(e)}")
                    else:
                        print(f"  [WARN] 添付ファイル情報が不完全", flush=True)
                        logger.info(f"行 {index+1}: 添付ファイル情報が不完全")
                else:
                    print(f"  [WARN] 添付ファイル番号 {attachment_no_int} が見つかりません", flush=True)
                    logger.info(f"行 {index+1}: 添付ファイル番号 {attachment_no_int} が見つかりません")

            except (ValueError, TypeError):
                print(f"  [WARN] 無効な添付ファイル番号: {attachment_no}", flush=True)
                logger.info(f"行 {index+1}: 無効な添付ファイル番号: {attachment_no}")
        else:
            print(f"  [INFO]  添付ファイルなし", flush=True)
        
        print(f"  [DONE] 行 {index+1} 処理完了", flush=True)
        logger.info(f"=== 行 {index+1} 処理完了 ===")

    # 更新されたlist.csvを保存
    print(f"\n{'='*60}", flush=True)
    print("list.csvを更新中...", flush=True)
    try:
        list_df.to_csv(list_csv_path, index=False, encoding='utf-8')
        print(f"[OK] list.csv更新完了: {list_csv_path}", flush=True)
        logger.info(f"\nlist.csv更新完了: {list_csv_path}")
    except Exception as e:
        print(f"[ERROR] list.csv保存エラー: {str(e)}", flush=True)
        logger.error(f"list.csv保存エラー: {str(e)}")
        return 1

    validation_errors = validate_generated_artifacts(list_df)
    if validation_errors:
        for error in validation_errors[:20]:
            print(f"[ERROR] {error}", flush=True)
            logger.error(error)
        if len(validation_errors) > 20:
            logger.error(f"生成物検証エラー: 他 {len(validation_errors) - 20} 件")
        print(f"[ERROR] 生成物検証に失敗しました: {len(validation_errors)}件", flush=True)
        return 1

    print(f"\n{'='*60}", flush=True)
    print("[OK] ビーコンファイル作成プログラム完了", flush=True)
    print(f"{'='*60}", flush=True)
    logger.info("ビーコンファイル作成プログラム完了")
    return 0

def main():
    import argparse
    
    # コマンドライン引数のパーシング
    parser = argparse.ArgumentParser(description='ビーコンファイル作成プログラム')
    parser.add_argument('--data-dir', default='/opt/training/bin/data',
                        help='データディレクトリのパス')
    parser.add_argument('--beacon-base', default=None,
                        help='ビーコンURLベース(キャンペーンのbeacon_base)。指定時はconfig.iniより優先')
    args = parser.parse_args()

    return create_beacon_files(args.data_dir, args.beacon_base)

if __name__ == "__main__":
    sys.exit(main())
