#!/usr/bin/env python3
"""
標的型メール訓練システム - メール送信プログラム
"""

# タイムゾーン設定
import os
os.environ['TZ'] = 'Asia/Tokyo'
os.environ['PYTHONUNBUFFERED'] = '1'
import sys
import time
time.tzset()

# 標準出力のバッファリングを無効化（リアルタイム出力用）
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(line_buffering=True)
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(line_buffering=True)

import pandas as pd
import smtplib
import argparse
import logging
import subprocess
import re
import json
import secrets
import sqlite3
from datetime import datetime
from pathlib import Path
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from email.mime.base import MIMEBase
from email import encoders
from email.utils import formataddr, formatdate, make_msgid

# グローバルのステータス/アラートファイル(後方互換用)。
# 並列送信では複数キャンペーンが同時に走るため、正となるのは
# 各キャンペーンの data_dir 配下(self.status_file / self.alert_file)。
# グローバルは「最後に更新したもの」が残る互換用にも書き続ける。
STOP_FILE = "/opt/training/bin/data/stop_sending.flag"
ALERT_FILE = "/opt/training/bin/data/send_alerts.json"
STATUS_FILE = "/opt/training/bin/data/send_status.json"
ATTACHMENT_ROOT = Path("/opt/training/bin/Attachment")
TET2_DB_PATH = "/opt/training/tet2-db/tet2.sqlite"


def _has_csv_value(value):
    return not pd.isna(value) and str(value).strip() not in ("", "nan")


def attachment_file_error(path_value, attachment_root=ATTACHMENT_ROOT):
    path = Path(str(path_value).strip())
    root = Path(attachment_root).resolve()
    try:
        resolved = path.resolve()
        resolved.relative_to(root)
    except (OSError, ValueError):
        return f"管理外の添付パスです: {path}"
    if not resolved.is_file():
        return f"添付ファイルが見つかりません: {path}"
    if resolved.stat().st_size == 0:
        return f"添付ファイルが空です: {path}"
    if not os.access(resolved, os.R_OK):
        return f"添付ファイルを読み取れません: {path}"
    return None


def required_attachment_error(row, attachment_root=ATTACHMENT_ROOT):
    """添付指定行のpath・実体・size・read権限をfail-closedで検証する。"""
    if not _has_csv_value(row.get('添付ファイル番号', '')):
        return None
    attachment_value = row.get('添付ファイル', '')
    if not _has_csv_value(attachment_value):
        return "必須添付のパスが空です"
    error = attachment_file_error(attachment_value, attachment_root)
    return f"必須{error}" if error is not None else None


class RecipientEligibilityError(RuntimeError):
    pass


class RecipientEligibilityGuard:
    """TET2 worker送信時だけ、各recipientの現行在籍状態をSMTP直前に確認する。"""

    def __init__(self, campaign_id, data_dir, db_path=TET2_DB_PATH):
        self.campaign_id = campaign_id
        self.data_dir = os.path.normpath(data_dir)
        uri = f"{Path(db_path).absolute().as_uri()}?mode=ro"
        self.conn = sqlite3.connect(uri, uri=True, timeout=5)
        self.conn.row_factory = sqlite3.Row
        self.conn.execute("PRAGMA query_only=ON")

    def close(self):
        self.conn.close()

    def assert_can_send(self, csv_row):
        tracking_id = csv_row.get("乱数列")
        original_email = csv_row.get("メールアドレス（会社）")
        if not _has_csv_value(tracking_id) or not _has_csv_value(original_email):
            raise RecipientEligibilityError("送信行の追跡IDまたは元の宛先がありません")
        try:
            record = self.conn.execute(
                """SELECT c.status AS campaign_status, c.tenant_id AS campaign_tenant,
                          c.data_dir, ct.send_status, t.tenant_id AS target_tenant,
                          t.status AS target_status, t.email AS target_email
                   FROM campaign_targets ct
                   JOIN campaigns c ON c.id=ct.campaign_id
                   JOIN targets t ON t.id=ct.target_id
                   WHERE ct.campaign_id=? AND ct.tracking_id=?""",
                (self.campaign_id, str(tracking_id).strip()),
            ).fetchone()
        except sqlite3.Error as error:
            raise RecipientEligibilityError("対象者の再確認に失敗しました") from error
        if record is None:
            raise RecipientEligibilityError("送信行に対応する対象者が見つかりません")
        if record["campaign_status"] not in ("scheduled", "running"):
            raise RecipientEligibilityError("キャンペーンが送信可能な状態ではありません")
        if os.path.normpath(record["data_dir"] or "") != self.data_dir:
            raise RecipientEligibilityError("キャンペーンの送信データ保存先が変わりました")
        if record["campaign_tenant"] != record["target_tenant"]:
            raise RecipientEligibilityError("別組織の対象者が含まれます")
        if record["target_status"] != "active":
            raise RecipientEligibilityError("停止・退職した対象者が含まれます")
        if record["send_status"] not in ("pending", "failed", "deferred"):
            raise RecipientEligibilityError("対象者の送信状態が変更されました")
        if record["target_email"] != str(original_email).strip():
            raise RecipientEligibilityError("対象者のメールアドレスが変更されました")


class FlushingStreamHandler(logging.StreamHandler):
    """リアルタイム出力用のStreamHandler（自動フラッシュ付き）"""
    def emit(self, record):
        super().emit(record)
        self.flush()


class FlushingFileHandler(logging.FileHandler):
    """リアルタイム出力用のFileHandler（自動フラッシュ付き）"""
    def emit(self, record):
        super().emit(record)
        self.flush()


class TargetedEmailSender:
    def __init__(self, data_dir="/opt/training/bin/data",
                 attachment_dir="/opt/training/bin/Attachment"):
        self.data_dir = data_dir
        self.attachment_dir = attachment_dir
        # キャンペーン別のステータス/アラート/停止フラグ(並列送信で相互上書きを防ぐ正)。
        self.status_file = os.path.join(data_dir, "send_status.json")
        self.alert_file = os.path.join(data_dir, "send_alerts.json")
        self.stop_file = os.path.join(data_dir, "stop_sending.flag")
        self.setup_logging()
        self.setup_operation_logging()
        
    def setup_logging(self):
        """エラーログ設定"""
        log_dir = os.path.join(self.data_dir, "logs")
        os.makedirs(log_dir, exist_ok=True)
        
        log_file = os.path.join(log_dir, f"email_send_{datetime.now().strftime('%Y%m%d_%H%M%S')}.log")
        
        logging.basicConfig(
            level=logging.INFO,
            format='%(asctime)s - %(levelname)s - %(message)s',
            handlers=[
                FlushingFileHandler(log_file, encoding='utf-8'),
                FlushingStreamHandler(sys.stdout)
            ]
        )
        self.logger = logging.getLogger(__name__)
        
    def setup_operation_logging(self):
        """動作ログ設定。

        operation.log は www-data(create_beacon_files.py)と training(send_email.py)が
        共有する固定ファイル。所有者/権限がずれるとこちらから書けないことがあるが、
        共有ログに書けないことを理由に「送信そのもの」を止めてはならない。
        書けない場合はファイルハンドラを諦め、個別ログ(setup_logging)側だけで継続する。
        (2026-08 に operation.log が www-data:644 で作られ、training の send_email が
         PermissionError で初期化失敗し campaign 90 が1通も送れなかった事故の恒久対策。)
        """
        operation_log_file = "/opt/training/logs/operation.log"

        # 動作ログ用の独立したロガーを作成
        self.operation_logger = logging.getLogger('operation_logger')
        self.operation_logger.setLevel(logging.INFO)

        # 既存のハンドラーをクリア
        for handler in self.operation_logger.handlers[:]:
            self.operation_logger.removeHandler(handler)

        # 動作ログファイルハンドラー追加。開けなければ握りつぶして送信は継続する。
        try:
            # 新規作成時に group 書き込みを許可(www-data と共有するため)。
            old_umask = os.umask(0o002)
            try:
                operation_handler = logging.FileHandler(operation_log_file, encoding='utf-8', mode='a')
            finally:
                os.umask(old_umask)
            operation_formatter = logging.Formatter('%(asctime)s - [send_email] - %(levelname)s - %(message)s')
            operation_handler.setFormatter(operation_formatter)
            self.operation_logger.addHandler(operation_handler)
            # 自分が所有者なら 664 に揃える(次回の他ユーザー用)。
            try:
                os.chmod(operation_log_file, 0o664)
            except OSError:
                pass
        except OSError as exc:
            # 共有ログに書けないだけ。送信本体は止めない。
            self.logger.warning(
                "operation.log を開けませんでした(%s)。動作ログ無しで送信を継続します。", exc
            )

    def check_stop_file(self):
        """停止ファイルをチェック(キャンペーン別の data_dir 配下)"""
        if os.path.exists(self.stop_file):
            self.logger.warning("🛑 停止ファイルが検出されました。送信を中止します。")
            self.operation_logger.warning("停止ファイル検出 - 送信中止")
            return True
        return False

    def _append_alert(self, path, alert):
        """指定ファイルにアラートを追記(最新100件保持)。"""
        alerts = []
        if os.path.exists(path):
            with open(path, 'r', encoding='utf-8') as f:
                try:
                    alerts = json.load(f)
                except json.JSONDecodeError:
                    alerts = []
        alerts.append(alert)
        if len(alerts) > 100:
            alerts = alerts[-100:]
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(alerts, f, ensure_ascii=False, indent=2)

    def write_alert(self, alert_type, message, to_email="", details=""):
        """アラートをJSONファイルに記録。

        正: キャンペーン別 self.alert_file(並列送信で混ざらない)。
        互換: グローバル ALERT_FILE にも追記(既存の集約参照が壊れないように)。
        """
        alert = {
            "timestamp": datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            "type": alert_type,
            "message": message,
            "to_email": to_email,
            "details": details,
        }
        try:
            self._append_alert(self.alert_file, alert)
        except Exception as e:
            self.logger.error(f"アラート記録エラー(campaign): {str(e)}")
        try:
            self._append_alert(ALERT_FILE, alert)  # 後方互換(グローバル)
        except Exception:
            pass
        self.logger.warning(f"🚨 アラート記録: [{alert_type}] {message}")
        self.operation_logger.warning(f"アラート: [{alert_type}] {message} - {to_email}")

    def update_status(self, status, processed=0, total=0, success=0, error=0, current_email=""):
        """送信ステータスをJSONファイルに記録。

        正: キャンペーン別 self.status_file(並列送信で上書きし合わない)。
        互換: グローバル STATUS_FILE にも書く(最後に更新したものが残る)。
        data_dir 名(campaign_{id})から campaign_id を推定して埋め、集約API側で識別できるようにする。
        """
        campaign_id = None
        base = os.path.basename(os.path.normpath(self.data_dir))
        if base.startswith("campaign_"):
            suffix = base[len("campaign_"):]
            if suffix.isdigit():
                campaign_id = int(suffix)
        try:
            status_data = {
                "timestamp": datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
                "status": status,
                "processed": processed,
                "total": total,
                "success": success,
                "error": error,
                "current_email": current_email,
                "campaign_id": campaign_id,
            }

            with open(self.status_file, 'w', encoding='utf-8') as f:
                json.dump(status_data, f, ensure_ascii=False, indent=2)
            try:
                with open(STATUS_FILE, 'w', encoding='utf-8') as f:  # 後方互換(グローバル)
                    json.dump(status_data, f, ensure_ascii=False, indent=2)
            except Exception:
                pass

        except Exception as e:
            self.logger.error(f"ステータス更新エラー: {str(e)}")

    def check_mail_log_for_issues(self, to_email):
        """メールログを確認して問題を検出"""
        try:
            result = subprocess.run(
                ['tail', '-200', '/var/log/mail.log'],
                capture_output=True,
                text=True,
                timeout=5
            )

            if result.returncode != 0:
                return None

            log_content = result.stdout

            # 接続拒否パターン
            refused_patterns = [
                (rf'to=<{re.escape(to_email)}>[^|]*status=deferred.*Connection refused', 'CONNECTION_REFUSED'),
                (rf'to=<{re.escape(to_email)}>[^|]*status=deferred.*Connection timed out', 'CONNECTION_TIMEOUT'),
                (rf'to=<{re.escape(to_email)}>[^|]*status=deferred.*temporarily rejected', 'TEMPORARILY_REJECTED'),
                (rf'to=<{re.escape(to_email)}>[^|]*status=deferred.*rate limit', 'RATE_LIMITED'),
                (rf'to=<{re.escape(to_email)}>[^|]*status=deferred.*greylisted', 'GREYLISTED'),
                (rf'to=<{re.escape(to_email)}>[^|]*status=deferred', 'DEFERRED'),
                (rf'to=<{re.escape(to_email)}>[^|]*status=bounced', 'BOUNCED'),
            ]

            for pattern, issue_type in refused_patterns:
                match = re.search(pattern, log_content, re.IGNORECASE)
                if match:
                    return {
                        'type': issue_type,
                        'detail': match.group(0)[:200]  # 最初の200文字
                    }

            return None

        except Exception as e:
            self.logger.error(f"メールログチェックエラー: {str(e)}")
            return None
        
    def load_csv_data(self):
        """CSVファイルの読み込み"""
        try:
            # リストファイル読み込み
            list_file = os.path.join(self.data_dir, "list.csv")
            # 乱数列(tracking_id)は先頭ゼロを含む10桁ID。数値推定させると
            # 先頭ゼロが落ちてDB同期・追跡が壊れるため、全列を文字列で読む。
            self.email_list = pd.read_csv(list_file, dtype=str)
            self.logger.info(f"リストファイル読み込み完了: {len(self.email_list)} 件")
            
            # 件名定型文読み込み
            kenmei_file = os.path.join(self.data_dir, "kenmei.csv")
            if os.path.exists(kenmei_file):
                self.subject_templates = pd.read_csv(kenmei_file)
                self.logger.info(f"件名定型文読み込み完了: {len(self.subject_templates)} 件")
            else:
                self.subject_templates = pd.DataFrame()
                self.logger.warning("件名定型文ファイルが見つかりません")
            
            # 本文定型文読み込み
            honbun_file = os.path.join(self.data_dir, "honbun.csv")
            self.body_templates = pd.read_csv(honbun_file)
            self.logger.info(f"本文定型文読み込み完了: {len(self.body_templates)} 件")
            
        except Exception as e:
            self.logger.error(f"CSVファイル読み込みエラー: {str(e)}")
            raise
    
    def get_subject_template(self, template_no):
        """件名定型文の取得"""
        try:
            template_no = int(float(template_no))
            if not self.subject_templates.empty:
                # 実際の件名があれば取得
                mask = self.subject_templates['項番'] == template_no
                if mask.any() and '件名' in self.subject_templates.columns:
                    return self.subject_templates.loc[mask, '件名'].iloc[0]
            
            # デフォルト件名
            default_subjects = {
                1: "【重要】クレジットカード不正利用の検出について",
                2: "セミナーアンケートのお願い",
                3: "会議議事録の送付",
                4: "懇親会のご案内"
            }
            return default_subjects.get(template_no, f"件名テンプレート{template_no}")
            
        except Exception as e:
            self.logger.error(f"件名テンプレート取得エラー: {str(e)}")
            return "件名なし"
    
    def get_body_template(self, template_no):
        """本文定型文の取得"""
        try:
            template_no = int(float(template_no))
            mask = self.body_templates['項番'] == template_no
            if mask.any():
                return self.body_templates.loc[mask, '本文定型文'].iloc[0]
            else:
                self.logger.warning(f"本文テンプレート {template_no} が見つかりません")
                return "本文テンプレートが見つかりません"
        except Exception as e:
            self.logger.error(f"本文テンプレート取得エラー: {str(e)}")
            return "本文取得エラー"
    
    def replace_placeholders(self, text, row):
        """本文差し込み処理"""
        if pd.isna(text):
            return ""

        # プレースホルダーと列名のマッピング
        placeholder_columns = {
            '#$1$#': '本文差し込み1 #$1$#',
            '#$2$#': '本文差し込み2 #$2$#',
            '#$3$#': '本文差し込み3 #$3$#',
            '#$4$#': '本文差し込み4 #$4$#'
        }

        for placeholder, column_name in placeholder_columns.items():
            value = row.get(column_name, '')

            # NaNまたは空の場合はプレースホルダーを削除
            if pd.isna(value) or value == '':
                text = text.replace(placeholder, '')
            else:
                # 値がある場合は文字列に変換して置換
                text = text.replace(placeholder, str(value))

        return text
    
    def attach_file(self, msg, file_path):
        """添付ファイル処理"""
        try:
            if not file_path or pd.isna(file_path):
                return True
                
            if not os.path.exists(file_path):
                self.logger.error(f"添付ファイルが見つかりません: {file_path}")
                return False
            
            with open(file_path, "rb") as attachment:
                part = MIMEBase('application', 'octet-stream')
                part.set_payload(attachment.read())
            
            encoders.encode_base64(part)
            
            # 日本語ファイル名のエンコーディング対応
            filename = os.path.basename(file_path)
            try:
                # ASCII文字のみかチェック
                filename.encode('ascii')
                # ASCII文字のみの場合はそのまま設定
                part.add_header(
                    'Content-Disposition',
                    f'attachment; filename="{filename}"'
                )
            except UnicodeEncodeError:
                # 日本語が含まれる場合はRFC2231準拠でエンコード
                from urllib.parse import quote
                encoded_filename = quote(filename.encode('utf-8'))
                part.add_header(
                    'Content-Disposition',
                    f'attachment; filename*=UTF-8\'\'{encoded_filename}'
                )
            msg.attach(part)
            self.logger.info(f"添付ファイル追加: {file_path}")
            return True
            
        except Exception as e:
            self.logger.error(f"添付ファイル処理エラー: {str(e)}")
            return False
    
    def send_email(self, to_email, from_email, subject, body, attachment_path=None, tracking_id=None,
                   eligibility_row=None):
        """メール送信（エラー処理強化版）"""
        try:
            # 入力値の検証
            if not to_email or not from_email:
                raise ValueError("送信先または送信元メールアドレスが空です")
                
            if not self.validate_email_format(to_email):
                raise ValueError(f"無効な送信先メールアドレス: {to_email}")
                
            if not self.validate_email_format(from_email):
                raise ValueError(f"無効な送信元メールアドレス: {from_email}")
            
            # メッセージ作成
            msg = MIMEMultipart()
            msg['From'] = formataddr(("", from_email))
            msg['To'] = to_email
            msg['Subject'] = subject
            msg['Date'] = formatdate(localtime=True)
            if isinstance(tracking_id, str) and re.fullmatch(r'[0-9]{10}', tracking_id):
                domain = from_email.rsplit('@', 1)[1]
                msg['Message-ID'] = f'<t{tracking_id}.{secrets.token_hex(16)}@{domain}>'
            else:
                msg['Message-ID'] = make_msgid()
            
            # 本文添付
            if not body:
                body = "（本文なし）"
            msg.attach(MIMEText(body, 'plain', 'utf-8'))
            
            # 添付ファイル処理
            if attachment_path and not pd.isna(attachment_path) and str(attachment_path).strip():
                # nanファイルは添付しない
                if "nan" in str(attachment_path).lower() and str(attachment_path).endswith(".nan"):
                    self.logger.info(f"nanファイルのため添付をスキップ: {attachment_path}")
                elif attachment_file_error(attachment_path, self.attachment_dir) is not None:
                    self.logger.error(f"添付ファイルのpath検証失敗、送信を中止: {to_email}")
                    return False
                elif not self.attach_file(msg, attachment_path):
                    self.logger.error(f"添付ファイル処理失敗、送信を中止: {to_email}")
                    return False
            
            # SMTPサーバー接続とメール送信
            try:
                with smtplib.SMTP('localhost', 25, timeout=30) as server:
                    # 接続確認
                    server.ehlo()
                    
                    # メール送信
                    text = msg.as_string()
                    if eligibility_row is not None:
                        guard = getattr(self, "recipient_guard", None)
                        if guard is None:
                            raise RecipientEligibilityError("送信直前確認が設定されていません")
                        guard.assert_can_send(eligibility_row)
                    refused = server.sendmail(from_email, [to_email], text)
                    
                    # 送信拒否チェック
                    if refused:
                        raise smtplib.SMTPRecipientsRefused(refused)
                    
                return True
                
            except smtplib.SMTPRecipientsRefused as e:
                self.logger.error(f"受信者拒否エラー ({to_email}): {str(e)}")
                return False
            except smtplib.SMTPAuthenticationError as e:
                self.logger.error(f"SMTP認証エラー: {str(e)}")
                return False
            except smtplib.SMTPConnectError as e:
                self.logger.error(f"SMTP接続エラー: {str(e)}")
                return False
            except smtplib.SMTPException as e:
                self.logger.error(f"SMTPエラー ({to_email}): {str(e)}")
                return False
            except ConnectionRefusedError:
                self.logger.error("SMTPサーバーへの接続が拒否されました")
                return False
            except TimeoutError:
                self.logger.error(f"SMTPサーバー接続タイムアウト ({to_email})")
                return False
                
        except RecipientEligibilityError:
            raise
        except ValueError as e:
            self.logger.error(f"メール送信パラメータエラー: {str(e)}")
            return False
        except Exception as e:
            self.logger.error(f"メール送信予期しないエラー ({to_email}): {str(e)}")
            return False
    
    def validate_email_data(self):
        """メールデータの事前検証"""
        self.logger.info("メールデータの検証開始")
        
        valid_count = 0
        invalid_count = 0
        consecutive_empty = 0
        max_consecutive_empty = 10  # 連続空行の許容数
        
        validation_errors = []
        required_attachment_errors = []
        
        for index, row in self.email_list.iterrows():
            # 送信先情報チェック
            to_email = row.get('送信先情報')
            if pd.isna(to_email) or not to_email or str(to_email).strip() == '':
                consecutive_empty += 1
                invalid_count += 1
                
                # 連続で空の宛先が続く場合は処理停止
                if consecutive_empty >= max_consecutive_empty:
                    error_msg = f"行 {index + 1}: 連続で{max_consecutive_empty}件以上の空の宛先が検出されました。処理を停止します。"
                    self.logger.error(error_msg)
                    raise ValueError(error_msg)
                    
                validation_errors.append(f"行 {index + 1}: 送信先情報が空です")
                continue
            else:
                consecutive_empty = 0  # 連続カウントをリセット
                
            # メールアドレス形式チェック
            if not self.validate_email_format(str(to_email)):
                validation_errors.append(f"行 {index + 1}: 無効なメールアドレス形式: {to_email}")
                invalid_count += 1
                continue
                
            # 送信元メールアドレスチェック
            from_email = row.get('送信元メールアドレス')
            if pd.isna(from_email) or not from_email:
                validation_errors.append(f"行 {index + 1}: 送信元メールアドレスが空です")
                invalid_count += 1
                continue
                
            if not self.validate_email_format(str(from_email)):
                validation_errors.append(f"行 {index + 1}: 無効な送信元メールアドレス形式: {from_email}")
                invalid_count += 1
                continue
                
            # 添付ファイルチェック。添付指定行は本文だけ送ることを禁止する。
            required_error = required_attachment_error(row, self.attachment_dir)
            if required_error is not None:
                message = f"行 {index + 1}: {required_error}"
                validation_errors.append(message)
                required_attachment_errors.append(message)
                invalid_count += 1
                continue
            attachment_path = row.get('添付ファイル')
            if attachment_path and not pd.isna(attachment_path) and str(attachment_path).strip():
                if not os.path.exists(attachment_path):
                    validation_errors.append(f"行 {index + 1}: 添付ファイルが見つかりません: {attachment_path}")
                    
            valid_count += 1
        
        # 検証結果のログ出力
        self.logger.info(f"データ検証完了 - 有効: {valid_count}件, 無効: {invalid_count}件")
        
        if validation_errors:
            self.logger.warning("検証エラーが検出されました:")
            for error in validation_errors[:20]:  # 最初の20件のみログ出力
                self.logger.warning(f"  {error}")
            if len(validation_errors) > 20:
                self.logger.warning(f"  ... 他 {len(validation_errors) - 20} 件のエラー")
                
        if required_attachment_errors:
            raise ValueError(
                f"必須添付の検証に失敗しました: {len(required_attachment_errors)}件"
            )
        if valid_count == 0:
            raise ValueError("有効な送信データが見つかりません")
            
        return valid_count, invalid_count, validation_errors
    
    def validate_email_format(self, email):
        """メールアドレス形式の検証"""
        # 通常のメールアドレス形式 + localhost対応
        pattern = r'^[a-zA-Z0-9._%+-]+@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,}|localhost)$'
        return re.match(pattern, email.strip()) is not None

    def check_mail_delivery(self, to_email, max_wait=10, check_interval=1):
        """メールログを確認して送信成功を検証"""
        try:
            wait_time = 0
            while wait_time < max_wait:
                time.sleep(check_interval)
                wait_time += check_interval

                # メールログの最新100行をチェック
                result = subprocess.run(
                    ['tail', '-100', '/var/log/mail.log'],
                    capture_output=True,
                    text=True,
                    timeout=5
                )

                if result.returncode != 0:
                    self.logger.warning(f"メールログ読み取りエラー: {result.stderr}")
                    continue

                # 送信先メールアドレスとstatus=sentを検索
                log_content = result.stdout
                # パターン: to=<email>, ... status=sent
                pattern = rf'to=<{re.escape(to_email)}>[^|]*status=sent'
                if re.search(pattern, log_content, re.IGNORECASE):
                    self.logger.info(f"📧 メールログで送信確認: {to_email}")
                    return True

                # status=deferredやstatus=bouncedも確認
                defer_pattern = rf'to=<{re.escape(to_email)}>[^|]*status=(deferred|bounced)'
                if re.search(defer_pattern, log_content, re.IGNORECASE):
                    self.logger.warning(f"⚠️ メール配信遅延/バウンス検出: {to_email}")
                    return False

            self.logger.warning(f"⏰ メールログ確認タイムアウト: {to_email} ({max_wait}秒)")
            return False

        except subprocess.TimeoutExpired:
            self.logger.error(f"メールログ読み取りタイムアウト: {to_email}")
            return False
        except Exception as e:
            self.logger.error(f"メールログ確認エラー: {str(e)}")
            return False

    def update_send_flag(self, row_index, flag_value="1"):
        """list.csvの送信フラグを更新

        dtype=str で読むため flag_value も文字列で統一する
        (数値を混ぜると列型が乱れ、書き戻し時の表記が揺れる)。
        """
        try:
            list_file = os.path.join(self.data_dir, "list.csv")

            # CSVを読み込み
            df = pd.read_csv(list_file, dtype=str)  # 乱数列の先頭ゼロ保持

            # 送信フラグ列がなければ追加
            if '送信フラグ' not in df.columns:
                df['送信フラグ'] = ''
                self.logger.info("送信フラグ列を追加しました")

            # 該当行のフラグを更新
            df.at[row_index, '送信フラグ'] = flag_value

            # CSVを保存
            df.to_csv(list_file, index=False, encoding='utf-8')
            self.logger.info(f"✅ 送信フラグ更新: 行{row_index + 1} = {flag_value}")

            return True

        except Exception as e:
            self.logger.error(f"送信フラグ更新エラー (行{row_index + 1}): {str(e)}")
            return False

    def find_resume_row(self):
        """送信フラグから再開行を特定（最後に送信済みの次の行）"""
        try:
            list_file = os.path.join(self.data_dir, "list.csv")
            df = pd.read_csv(list_file, dtype=str)  # 乱数列の先頭ゼロ保持

            if '送信フラグ' not in df.columns:
                self.logger.info("送信フラグ列がありません。最初から開始します。")
                return 0

            # 送信フラグが1の行を検索
            last_sent_index = -1
            for index, row in df.iterrows():
                flag = row.get('送信フラグ')
                if pd.notna(flag):
                    try:
                        if int(float(flag)) == 1:
                            last_sent_index = index
                    except (ValueError, TypeError):
                        continue

            if last_sent_index == -1:
                self.logger.info("送信済みの行がありません。最初から開始します。")
                return 0

            resume_row = last_sent_index + 1
            self.logger.info(f"📍 最後の送信済み行: {last_sent_index + 1}, 再開行: {resume_row + 1}")
            return resume_row

        except Exception as e:
            self.logger.error(f"再開行検索エラー: {str(e)}")
            return 0

    def get_row_by_koban(self, koban, last=False):
        """項番から行インデックスを取得

        last=False: その項番が最初に現れる行（開始行用）
        last=True : その項番が最後に現れる行（終了行用）
        全員に全コンテンツ配信では1項番が複数行になるため、終了行は
        最終出現行を返さないと末尾のコンテンツ行が送信範囲から漏れる。
        """
        try:
            list_file = os.path.join(self.data_dir, "list.csv")
            df = pd.read_csv(list_file, dtype=str)  # 乱数列の先頭ゼロ保持

            if '項番' not in df.columns:
                self.logger.warning("項番列が見つかりません")
                return None

            matched = None
            for index, row in df.iterrows():
                row_koban = row.get('項番')
                if pd.notna(row_koban):
                    try:
                        if int(float(row_koban)) == koban:
                            if not last:
                                return index
                            matched = index  # 最終出現を追い続ける
                    except (ValueError, TypeError):
                        continue

            if matched is not None:
                return matched

            self.logger.warning(f"項番 {koban} が見つかりません")
            return None

        except Exception as e:
            self.logger.error(f"項番検索エラー: {str(e)}")
            return None

    def get_send_status_summary(self):
        """送信状況のサマリーを取得"""
        try:
            list_file = os.path.join(self.data_dir, "list.csv")
            df = pd.read_csv(list_file, dtype=str)  # 乱数列の先頭ゼロ保持

            total = len(df)
            sent = 0
            not_sent = 0

            if '送信フラグ' in df.columns:
                for index, row in df.iterrows():
                    flag = row.get('送信フラグ')
                    try:
                        if pd.notna(flag) and int(float(flag)) == 1:
                            sent += 1
                        else:
                            not_sent += 1
                    except (ValueError, TypeError):
                        not_sent += 1
            else:
                not_sent = total

            return {
                'total': total,
                'sent': sent,
                'not_sent': not_sent,
                'resume_row': self.find_resume_row()
            }

        except Exception as e:
            self.logger.error(f"送信状況取得エラー: {str(e)}")
            return None
    
    def send_bulk_emails(self, interval=3, start_row=None, end_row=None, resume=False,
                         auto_pause=False, pause_threshold=10, pause_duration=60, max_retries=3,
                         adaptive_interval=False, max_interval=30):
        """一括メール送信

        Args:
            interval: 送信間隔（秒）
            start_row: 開始行インデックス（0始まり）。Noneの場合は最初から
            end_row: 終了行インデックス（0始まり）。Noneの場合は最後まで
            resume: Trueの場合、送信フラグから自動的に再開行を検出
        """
        # 再開モードの場合、送信フラグから再開行を自動検出
        if resume:
            start_row = self.find_resume_row()
            self.logger.info(f"🔄 再開モード: 行 {start_row + 1} から開始")
        elif start_row is not None:
            self.logger.info(f"🔄 指定行から開始: 行 {start_row + 1}")

        if end_row is not None:
            self.logger.info(f"🏁 終了行: 行 {end_row + 1} まで送信")

        self.logger.info("一括メール送信開始")
        self.operation_logger.info(f"一括メール送信開始 (送信間隔: {interval}秒, 開始行: {(start_row or 0) + 1}, 終了行: {(end_row + 1) if end_row is not None else '最後まで'})")

        # データ検証
        try:
            valid_count, invalid_count, validation_errors = self.validate_email_data()
            self.logger.info(f"送信対象: {valid_count}件 (スキップ: {invalid_count}件)")
        except ValueError as e:
            self.logger.error(f"データ検証エラー: {str(e)}")
            self.update_status("error", 0, 0, 0, 0, "")
            raise

        success_count = 0
        error_count = 0
        processed_count = 0
        deferred_count = 0  # 遅延カウント
        current_interval = interval  # 現在の送信間隔（適応型用）
        pause_count = 0  # 一時停止回数
        consecutive_success = 0  # 連続成功カウント（間隔復元用）

        if auto_pause:
            self.logger.info(f"🛡️ 自動一時停止: 有効 (閾値={pause_threshold}件, 待機={pause_duration}秒, 最大リトライ={max_retries}回)")
        if adaptive_interval:
            self.logger.info(f"📊 適応型送信間隔: 有効 (基本={interval}秒, 最大={max_interval}秒)")

        # ステータス初期化
        self.update_status("running", 0, valid_count, 0, 0, "")

        # スキップカウント
        skipped_count = 0

        for index, row in self.email_list.iterrows():
            try:
                # 開始行より前の行はスキップ
                if start_row is not None and index < start_row:
                    skipped_count += 1
                    continue

                # 終了行を超えたら送信終了
                if end_row is not None and index > end_row:
                    self.logger.info(f"🏁 終了項番に到達しました（行 {end_row + 1} まで送信完了）")
                    break

                # 停止ファイルチェック
                if self.check_stop_file():
                    self.update_status("stopped", processed_count, valid_count, success_count, error_count, "")
                    self.logger.warning("🛑 送信が停止されました")
                    break

                # 送信先情報チェック
                to_email = row.get('送信先情報')
                if pd.isna(to_email) or not to_email or str(to_email).strip() == '':
                    continue  # スキップ（既に検証済み）

                # メールアドレス形式チェック
                if not self.validate_email_format(str(to_email)):
                    continue  # スキップ（既に検証済み）

                # 送信済みチェック（送信フラグが1の行はスキップ）
                send_flag = row.get('送信フラグ')
                if pd.notna(send_flag):
                    try:
                        if int(float(send_flag)) == 1:
                            self.logger.info(f"⏭️ 行 {index + 1} (項番 {row.get('項番', '?')}): 送信済みのためスキップ")
                            skipped_count += 1
                            continue
                    except (ValueError, TypeError):
                        self.logger.warning(f"⚠️ 行 {index + 1}: 送信フラグの値が不正です: {send_flag}")
                        pass  # 不正な値は未送信として扱う

                # 送信元メールアドレス検証
                from_email = row.get('送信元メールアドレス')
                if pd.isna(from_email) or not from_email:
                    self.logger.error(f"行 {index + 1}: 送信元メールアドレスが空です")
                    error_count += 1
                    continue

                if not self.validate_email_format(str(from_email)):
                    self.logger.error(f"行 {index + 1}: 無効な送信元メールアドレス: {from_email}")
                    error_count += 1
                    continue

                processed_count += 1
                self.logger.info(f"処理中 {processed_count}/{valid_count - skipped_count}: {to_email} (行 {index + 1})")
                self.update_status("running", processed_count, valid_count - skipped_count, success_count, error_count, to_email)
                
                # 件名作成
                try:
                    subject_template_no = row.get('件名定型文No', 1)
                    subject = self.get_subject_template(subject_template_no)
                    if not subject or subject == "件名なし":
                        self.logger.warning(f"行 {index + 1}: 件名テンプレートが見つかりません (No: {subject_template_no})")
                        subject = f"件名テンプレート{subject_template_no}"
                    # (2026-08-06) 件名にも本文と同じ差し込み処理を適用する。
                    # 件名テンプレートに #$1$#〜#$4$#(例: #$2$#=氏名)が含まれる場合、
                    # 従来は未置換のまま送信されていた(本文だけ replace_placeholders していた)。
                    subject = self.replace_placeholders(subject, row)
                except Exception as e:
                    self.logger.error(f"行 {index + 1}: 件名作成エラー: {str(e)}")
                    subject = "件名エラー"
                
                # 本文作成
                try:
                    body_template_no = row.get('本文定型文No', 1)
                    body = self.get_body_template(body_template_no)
                    if not body or body == "本文取得エラー":
                        self.logger.warning(f"行 {index + 1}: 本文テンプレートが見つかりません (No: {body_template_no})")
                        body = f"本文テンプレート{body_template_no}が見つかりません"
                    else:
                        body = self.replace_placeholders(body, row)
                except Exception as e:
                    self.logger.error(f"行 {index + 1}: 本文作成エラー: {str(e)}")
                    body = "本文作成エラーが発生しました"
                
                # 添付ファイル検証
                required_error = required_attachment_error(row, self.attachment_dir)
                if required_error is not None:
                    raise ValueError(f"行 {index + 1}: {required_error}")
                attachment_path = row.get('添付ファイル')
                if attachment_path and not pd.isna(attachment_path) and str(attachment_path).strip():
                    # nanファイルの場合は添付なしとして処理
                    if "nan" in str(attachment_path).lower() and str(attachment_path).endswith(".nan"):
                        self.logger.info(f"行 {index + 1}: nanファイルのため添付なしで送信: {attachment_path}")
                        attachment_path = None
                    elif not os.path.exists(attachment_path):
                        self.logger.warning(f"行 {index + 1}: 添付ファイルが見つかりません: {attachment_path}")
                        attachment_path = None
                
                # SMTP接続テスト
                try:
                    with smtplib.SMTP('localhost', 25, timeout=10) as server:
                        server.noop()  # 接続テスト
                except Exception as e:
                    self.logger.error(f"SMTPサーバー接続エラー: {str(e)}")
                    self.logger.error("メール送信を中止します")
                    break

                # TEST転送でも元の対象者を照合する。配信準備後に退職・停止・組織変更が
                # 起きた場合は、残りの行を送らずworkerに非0終了を返してキャンペーンを止める。
                guard = getattr(self, "recipient_guard", None)
                if guard is not None:
                    guard.assert_can_send(row)
                
                # メール送信
                try:
                    send_options = {"tracking_id": row.get('乱数')}
                    if guard is not None:
                        send_options["eligibility_row"] = row
                    if self.send_email(to_email, from_email, subject, body, attachment_path,
                                       **send_options):
                        # メールログで送信確認
                        self.logger.info(f"📤 SMTP送信完了、メールログ確認中... {to_email}")
                        if self.check_mail_delivery(to_email, max_wait=15, check_interval=2):
                            # 送信フラグを更新
                            self.update_send_flag(index, "1")
                            success_count += 1
                            self.logger.info(f"✅ 送信成功・確認済 ({success_count}/{valid_count}): {to_email}")
                            deferred_count = 0  # 正常配信でリセット
                            consecutive_success += 1
                            # 適応型間隔: 連続成功で間隔を徐々に戻す
                            if adaptive_interval and current_interval > interval and consecutive_success >= 5:
                                old_interval = current_interval
                                current_interval = max(current_interval // 2, interval)
                                if current_interval != old_interval:
                                    self.logger.info(f"📊 適応型間隔: {old_interval}秒 → {current_interval}秒 に短縮（連続{consecutive_success}件成功）")
                                consecutive_success = 0
                        else:
                            # メールログで問題を検出
                            issue = self.check_mail_log_for_issues(to_email)
                            if issue:
                                deferred_count += 1
                                alert_messages = {
                                    'CONNECTION_REFUSED': '相手サーバーから接続拒否されました',
                                    'CONNECTION_TIMEOUT': '相手サーバーへの接続がタイムアウトしました',
                                    'TEMPORARILY_REJECTED': '一時的に拒否されました（レートリミット等）',
                                    'RATE_LIMITED': 'レートリミットにより制限されています',
                                    'GREYLISTED': 'グレイリストにより遅延しています',
                                    'DEFERRED': 'メール配信が遅延しています',
                                    'BOUNCED': 'メールがバウンスしました',
                                }
                                alert_msg = alert_messages.get(issue['type'], '配信に問題が発生しました')
                                self.write_alert(issue['type'], alert_msg, to_email, issue['detail'])
                                self.logger.error(f"🚨 アラート: {alert_msg} - {to_email}")

                                # 適応型送信間隔: 遅延検知時に間隔を延長
                                if adaptive_interval and deferred_count >= 3:
                                    old_interval = current_interval
                                    current_interval = min(current_interval * 2, max_interval)
                                    if current_interval != old_interval:
                                        self.logger.warning(f"📊 適応型間隔: {old_interval}秒 → {current_interval}秒 に延長")
                                    consecutive_success = 0

                                # 連続でdeferredが発生した場合は警告
                                if deferred_count >= 3:
                                    self.write_alert('MULTIPLE_DEFERRED',
                                                   f'連続{deferred_count}件の配信遅延が発生しています。送信を一時停止することを推奨します。',
                                                   '', '')
                                    self.logger.error(f"🚨🚨🚨 警告: 連続{deferred_count}件の配信遅延！送信停止を検討してください")

                                # 自動一時停止: 閾値到達で一時停止
                                if auto_pause and deferred_count >= pause_threshold:
                                    pause_count += 1
                                    if pause_count > max_retries:
                                        self.logger.error(f"🛑 最大リトライ回数({max_retries}回)に到達。送信を停止します。")
                                        self.write_alert('AUTO_STOP',
                                                        f'連続遅延により自動停止（リトライ{max_retries}回超過）',
                                                        '', '')
                                        self.update_status("stopped", processed_count, valid_count, success_count, error_count, "")
                                        break
                                    # エクスポネンシャルバックオフ: 待機時間を倍増
                                    actual_pause = pause_duration * (2 ** (pause_count - 1))
                                    self.logger.warning(f"⏸️ 自動一時停止: 連続{deferred_count}件の遅延検知。{actual_pause}秒間待機します（{pause_count}/{max_retries}回目）")
                                    self.write_alert('AUTO_PAUSE',
                                                    f'連続{deferred_count}件の遅延により自動一時停止（{actual_pause}秒待機、{pause_count}/{max_retries}回目）',
                                                    '', '')
                                    self.update_status("paused", processed_count, valid_count, success_count, error_count, "")
                                    time.sleep(actual_pause)
                                    deferred_count = 0  # リセットして再開
                                    self.logger.info(f"▶️ 送信再開（一時停止 {pause_count}/{max_retries}回目から復帰）")
                                    self.update_status("running", processed_count, valid_count, success_count, error_count, "")

                                # SMTP送信自体は成功しているのでフラグは立てる
                                self.update_send_flag(index, "1")
                                success_count += 1
                            else:
                                # メールログで確認できなかったがSMTP送信は成功
                                self.update_send_flag(index, "1")
                                success_count += 1
                                self.logger.warning(f"⚠️ SMTP送信成功（ログ未確認）({success_count}/{valid_count}): {to_email}")
                                deferred_count = 0  # リセット
                                consecutive_success += 1
                    else:
                        error_count += 1
                        self.logger.error(f"❌ 送信失敗: {to_email}")
                        self.write_alert('SEND_FAILED', f'メール送信に失敗しました', to_email, '')
                except RecipientEligibilityError:
                    raise
                except Exception as e:
                    error_count += 1
                    self.logger.error(f"❌ 送信例外エラー ({to_email}): {str(e)}")
                    self.write_alert('SEND_EXCEPTION', f'送信中に例外が発生: {str(e)}', to_email, '')
                
                # 送信間隔（最後のメール以外）
                if processed_count < valid_count:
                    self.logger.info(f"⏳ 待機中... {current_interval}秒")
                    time.sleep(current_interval)
                
            except KeyboardInterrupt:
                self.logger.warning("ユーザーによる中断が検出されました")
                break
            except RecipientEligibilityError as e:
                self.logger.error(f"送信直前の対象者確認に失敗: {e}")
                self.update_status("stopped", processed_count, valid_count, success_count, error_count, "")
                raise
            except Exception as e:
                self.logger.error(f"行 {index + 1} 予期しないエラー: {str(e)}")
                error_count += 1
                # 重大なエラーの場合は処理を停止
                if "SMTP" in str(e) or "Connection" in str(e):
                    self.logger.error("重大なエラーのため処理を停止します")
                    break
        
        # 最終結果
        total_processed = success_count + error_count
        self.logger.info("=" * 50)
        self.logger.info(f"送信処理完了")
        self.logger.info(f"  処理対象: {valid_count}件")
        self.logger.info(f"  実際処理: {total_processed}件")
        self.logger.info(f"  送信成功: {success_count}件")
        self.logger.info(f"  送信失敗: {error_count}件")
        if deferred_count > 0:
            self.logger.info(f"  配信遅延: {deferred_count}件")
        if invalid_count > 0:
            self.logger.info(f"  スキップ: {invalid_count}件")
        self.logger.info("=" * 50)

        # 最終ステータス更新
        final_status = "completed"
        if self.check_stop_file():
            final_status = "stopped"
        elif error_count > 0:
            final_status = "completed_with_errors"
        self.update_status(final_status, processed_count, valid_count, success_count, error_count, "")
    
    def run(self, interval=3, start_row=None, start_koban=None, end_koban=None, resume=False,
            auto_pause=False, pause_threshold=10, pause_duration=60, max_retries=3,
            adaptive_interval=False, max_interval=30):
        """メイン処理

        Args:
            interval: 送信間隔（秒）
            start_row: 開始行番号（1始まり、CSVの行番号）
            start_koban: 開始項番
            end_koban: 終了項番（この項番を含む）
            resume: Trueの場合、送信フラグから自動的に再開
            auto_pause: 連続遅延時に自動一時停止する
            pause_threshold: 自動停止の閾値（連続遅延件数）
            pause_duration: 一時停止の基本待機時間（秒）
            max_retries: 最大一時停止回数
            adaptive_interval: 遅延検知時に送信間隔を自動調整する
            max_interval: 適応型間隔の最大値（秒）
        """
        try:
            self.operation_logger.info("メール送信プログラム開始")
            self.load_csv_data()

            # 送信状況を表示
            status = self.get_send_status_summary()
            if status:
                self.logger.info("=" * 50)
                self.logger.info("📊 送信状況サマリー")
                self.logger.info(f"  総件数: {status['total']}件")
                self.logger.info(f"  送信済み: {status['sent']}件")
                self.logger.info(f"  未送信: {status['not_sent']}件")
                if status['resume_row'] > 0:
                    self.logger.info(f"  再開推奨行: {status['resume_row'] + 1}")
                self.logger.info("=" * 50)

            # 開始行の決定
            actual_start_row = None

            if resume:
                # 再開モード: 送信フラグから自動検出
                actual_start_row = self.find_resume_row()
            elif start_koban is not None:
                # 項番指定
                row_index = self.get_row_by_koban(start_koban)
                if row_index is not None:
                    actual_start_row = row_index
                    self.logger.info(f"📍 項番 {start_koban} → 行 {row_index + 1} から開始")
                else:
                    self.logger.error(f"項番 {start_koban} が見つかりません")
                    return
            elif start_row is not None:
                # 行番号指定（1始まり→0始まりに変換）
                actual_start_row = start_row - 1
                if actual_start_row < 0:
                    actual_start_row = 0

            # 終了行の決定
            actual_end_row = None
            if end_koban is not None:
                end_row_index = self.get_row_by_koban(end_koban, last=True)
                if end_row_index is not None:
                    actual_end_row = end_row_index
                    self.logger.info(f"🏁 終了項番 {end_koban} → 行 {end_row_index + 1} まで送信")
                else:
                    self.logger.error(f"終了項番 {end_koban} が見つかりません")
                    return

            # 開始行 > 終了行の検証（懸念点B・C対応）
            if actual_end_row is not None and actual_start_row is not None:
                if actual_start_row > actual_end_row:
                    if resume:
                        self.logger.warning(f"⚠️ 自動再開位置（行 {actual_start_row + 1}）が終了項番（行 {actual_end_row + 1}）を超えています。送信対象がありません。")
                    else:
                        self.logger.warning(f"⚠️ 開始位置（行 {actual_start_row + 1}）が終了項番（行 {actual_end_row + 1}）を超えています。送信対象がありません。")
                    self.update_status("completed", 0, 0, 0, 0, "")
                    return

            self.send_bulk_emails(interval, start_row=actual_start_row, end_row=actual_end_row, resume=resume,
                                 auto_pause=auto_pause, pause_threshold=pause_threshold,
                                 pause_duration=pause_duration, max_retries=max_retries,
                                 adaptive_interval=adaptive_interval, max_interval=max_interval)
            self.operation_logger.info("メール送信プログラム正常終了")
        except Exception as e:
            self.logger.error(f"実行エラー: {str(e)}")
            self.operation_logger.error(f"メール送信プログラムエラー: {str(e)}")
            raise

def main():
    parser = argparse.ArgumentParser(description='標的型メール訓練システム')
    parser.add_argument('--interval', '-i', type=int, default=3,
                       help='メール送信間隔（秒）デフォルト: 3秒')
    parser.add_argument('--data-dir', '-d', type=str,
                       default='/opt/training/bin/data',
                       help='データディレクトリパス')
    parser.add_argument('--attachment-dir', '-a', type=str,
                       default='/opt/training/bin/Attachment',
                       help='添付ファイルディレクトリパス')
    parser.add_argument('--resume', '-r', action='store_true',
                       help='送信フラグから自動的に再開する')
    parser.add_argument('--start-row', '-s', type=int, default=None,
                       help='開始行番号（1始まり）を指定して開始')
    parser.add_argument('--start-koban', '-k', type=int, default=None,
                       help='開始項番を指定して開始')
    parser.add_argument('--end-koban', '-e', type=int, default=None,
                       help='終了項番を指定（この項番を含む）')
    parser.add_argument('--campaign-id', type=int, default=None,
                       help='TET2 worker起動時の対象者再確認に使うキャンペーンID')
    parser.add_argument('--auto-pause', action='store_true',
                       help='連続遅延時に自動一時停止する')
    parser.add_argument('--pause-threshold', type=int, default=10,
                       help='自動停止の閾値（連続遅延件数）デフォルト: 10件')
    parser.add_argument('--pause-duration', type=int, default=60,
                       help='一時停止の基本待機時間（秒）デフォルト: 60秒')
    parser.add_argument('--max-retries', type=int, default=3,
                       help='最大一時停止回数 デフォルト: 3回')
    parser.add_argument('--adaptive-interval', action='store_true',
                       help='遅延検知時に送信間隔を自動調整する')
    parser.add_argument('--max-interval', type=int, default=30,
                       help='適応型間隔の最大値（秒）デフォルト: 30秒')
    parser.add_argument('--status', action='store_true',
                       help='送信状況のみ表示して終了')

    args = parser.parse_args()

    sender = TargetedEmailSender(args.data_dir, args.attachment_dir)

    # ステータス表示モード
    if args.status:
        sender.load_csv_data()
        status = sender.get_send_status_summary()
        if status:
            print("=" * 50, flush=True)
            print("📊 送信状況サマリー", flush=True)
            print(f"  総件数: {status['total']}件", flush=True)
            print(f"  送信済み: {status['sent']}件", flush=True)
            print(f"  未送信: {status['not_sent']}件", flush=True)
            if status['resume_row'] > 0:
                print(f"  再開推奨行: {status['resume_row'] + 1}", flush=True)
            print("=" * 50, flush=True)
        return

    if args.campaign_id is not None:
        if args.campaign_id < 1:
            parser.error('--campaign-id は1以上で指定してください')
        sender.recipient_guard = RecipientEligibilityGuard(args.campaign_id, args.data_dir)

    # 二重起動チェック（python3プロセスのみ検出、sudo/bashラッパーを除外）
    try:
        result = subprocess.run(
            ['pgrep', '-f', '^(/usr/bin/)?python3.*send_email\\.py'],
            capture_output=True, text=True
        )
        if result.returncode == 0:
            pids = [pid.strip() for pid in result.stdout.strip().split('\n') if pid.strip()]
            my_pid = str(os.getpid())
            other_pids = [pid for pid in pids if pid != my_pid]
            if other_pids:
                msg = f"⚠️ send_email.py が既に実行中です (PID: {', '.join(other_pids)})。二重起動を防止するため終了します。"
                print(msg, flush=True)
                sender.operation_logger.warning(msg)
                sys.exit(1)
    except Exception as e:
        print(f"⚠️ プロセスチェック中にエラー: {e}", flush=True)

    print("標的型メール訓練システム開始", flush=True)
    print(f"送信間隔: {args.interval}秒", flush=True)

    if args.resume:
        print("🔄 再開モード: 送信フラグから自動検出", flush=True)
    elif args.start_koban:
        print(f"📍 項番 {args.start_koban} から開始", flush=True)
    elif args.start_row:
        print(f"📍 行 {args.start_row} から開始", flush=True)

    if args.end_koban:
        print(f"🏁 項番 {args.end_koban} まで送信", flush=True)

    if args.auto_pause:
        print(f"🛡️ 自動一時停止: 有効 (閾値={args.pause_threshold}件, 待機={args.pause_duration}秒, 最大リトライ={args.max_retries}回)", flush=True)
    if args.adaptive_interval:
        print(f"📊 適応型送信間隔: 有効 (最大={args.max_interval}秒)", flush=True)

    try:
        sender.run(
            interval=args.interval,
            start_row=args.start_row,
            start_koban=args.start_koban,
            end_koban=args.end_koban,
            resume=args.resume,
            auto_pause=args.auto_pause,
            pause_threshold=args.pause_threshold,
            pause_duration=args.pause_duration,
            max_retries=args.max_retries,
            adaptive_interval=args.adaptive_interval,
            max_interval=args.max_interval
        )
    finally:
        if hasattr(sender, "recipient_guard"):
            sender.recipient_guard.close()

if __name__ == "__main__":
    main()
