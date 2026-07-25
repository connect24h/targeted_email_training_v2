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
import qrcode
from datetime import datetime

# 標準出力のバッファリングを無効化
sys.stdout.reconfigure(line_buffering=True)

def setup_logging():
    """ログ設定"""
    log_file = "/opt/training/logs/operation.log"

    # ファイルハンドラー（詳細ログ）
    file_handler = logging.FileHandler(log_file, encoding='utf-8', mode='a')
    file_handler.setLevel(logging.INFO)
    file_handler.setFormatter(logging.Formatter('%(asctime)s - [create_beacon_files] - %(levelname)s - %(message)s'))

    # ストリームハンドラー（標準出力には出力しない）
    # print文で詳細表示するため、ログはファイルのみに記録

    logger = logging.getLogger(__name__)
    logger.setLevel(logging.INFO)
    logger.addHandler(file_handler)
    # コンソールハンドラーは追加しない

    return logger

def create_beacon_files(data_dir='/opt/training/bin/data'):
    """ビーコンファイルの作成"""
    logger = setup_logging()

    print(f"\n{'='*60}", flush=True)
    print("ビーコンファイル作成プログラム開始", flush=True)
    print(f"{'='*60}", flush=True)
    logger.info("ビーコンファイル作成プログラム開始")

    # CSVファイル読み込み
    list_csv_path = os.path.join(data_dir, "list.csv")
    attachment_csv_path = os.path.join(data_dir, "Attachment.csv")

    try:
        list_df = pd.read_csv(list_csv_path)
        attachment_df = pd.read_csv(attachment_csv_path)
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
        return
    
    # ビーコンマスターファイルパス
    # ビーコンマスターファイルとHTMLマスターファイルは固定パス
    beacon_master = "/opt/training/bin/__BeaconMst.png"

    if not os.path.exists(beacon_master):
        logger.info(f"エラー: ビーコンマスターファイルが見つかりません: {beacon_master}")
        return

    # HTMLマスターファイルの読み込み関数
    def load_html_master(auth_flag):
        """認証フラグに応じたHTMLマスターファイルを読み込む"""
        # 認証フラグに応じてHTMLファイルを選択
        html_files = {
            0: "/opt/training/bin/master.html",   # 通常
            1: "/opt/training/bin/master2.html",  # Box
            2: "/opt/training/bin/master3.html",  # MS365
            3: "/opt/training/bin/master4.html",  # デジタルアーツ
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
            print(f"  [FILE] 使用するHTMLマスター: {os.path.basename(html_master_path)}", flush=True)
            logger.info(f"使用するHTMLマスター: {os.path.basename(html_master_path)}")
        except Exception as e:
            print(f"  [ERROR] HTMLマスターファイル読み込みエラー: {str(e)}", flush=True)
            logger.info(f"HTMLマスターファイル読み込みエラー: {str(e)}")
            continue

        # config.iniからビーコンURLベースを取得してテンプレート内のURLを置換
        cfg = configparser.ConfigParser()
        cfg_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'config.ini')
        beacon_base = 'http://85.131.251.224'
        if os.path.exists(cfg_path):
            cfg.read(cfg_path)
            beacon_base = cfg.get('server', 'beacon_url_base', fallback=beacon_base).rstrip('/')

        # プレースホルダーの置換
        html_content = master_html_content.replace('#$5$#', random_value)
        html_content = html_content.replace('#$6$#', to_email)  # 送信先メールアドレスを置換
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

                        # QR型の処理（拡張子が'qr'の場合）
                        if extension.lower() == 'qr':
                            print(f"  [QR] QRコード型添付ファイル処理開始", flush=True)
                            try:
                                # 追跡URLの構築
                                tracking_url = f"{beacon_base}/link-{random_value}.html"
                                print(f"     [QR] エンコード対象URL: {tracking_url}", flush=True)
                                logger.info(f"QRコード対象URL: {tracking_url}")

                                # QRコード画像の生成
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

                                # ZIPフラグが1の場合、ZIPファイル化
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
                            attachment_html_content = attachment_html_content.replace('#$5$#', random_value)
                            attachment_html_content = attachment_html_content.replace('#$6$#', to_email)

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
        logger.info(f"list.csv保存エラー: {str(e)}")

    print(f"\n{'='*60}", flush=True)
    print("[OK] ビーコンファイル作成プログラム完了", flush=True)
    print(f"{'='*60}", flush=True)
    logger.info("ビーコンファイル作成プログラム完了")

def main():
    import argparse
    
    # コマンドライン引数のパーシング
    parser = argparse.ArgumentParser(description='ビーコンファイル作成プログラム')
    parser.add_argument('--data-dir', default='/opt/training/bin/data',
                        help='データディレクトリのパス')
    args = parser.parse_args()
    
    create_beacon_files(args.data_dir)

if __name__ == "__main__":
    main()
