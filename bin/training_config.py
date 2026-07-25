#!/usr/bin/env python3
"""
標的型メール訓練システム - 設定管理モジュール
環境変数による設定外部化対応
"""

import os
from pathlib import Path

class TrainingConfig:
    """訓練システムの設定管理クラス"""
    
    def __init__(self):
        # 環境変数から設定を読み込み、デフォルト値を設定
        self._load_config()
    
    def _load_config(self):
        """環境変数から設定を読み込み"""
        # システムディレクトリ
        self.TRAINING_HOME = os.getenv('TRAINING_HOME', '/opt/training')
        self.TRAINING_BIN_DIR = os.getenv('TRAINING_BIN_DIR', '/opt/training/bin')
        self.TRAINING_DATA_DIR = os.getenv('TRAINING_DATA_DIR', '/var/lib/training/data')
        self.TRAINING_ATTACHMENT_DIR = os.getenv('TRAINING_ATTACHMENT_DIR', '/var/lib/training/attachment')
        self.TRAINING_LOG_DIR = os.getenv('TRAINING_LOG_DIR', '/var/log/training')
        
        # Web統合ディレクトリ
        self.WEB_DATA_DIR = os.getenv('WEB_DATA_DIR', '/var/www/html/tet/data')
        self.WEB_BEACON_DIR = os.getenv('WEB_BEACON_DIR', '/var/www/html')
        
        # ログファイル
        self.OPERATION_LOG_FILE = os.getenv('OPERATION_LOG_FILE', '/var/log/training/operation.log')
        self.EMAIL_LOG_DIR = os.getenv('EMAIL_LOG_DIR', '/var/log/training/email')
        
        # メール設定
        self.SMTP_HOST = os.getenv('SMTP_HOST', 'localhost')
        self.SMTP_PORT = int(os.getenv('SMTP_PORT', '25'))
        self.MAIL_DOMAIN = os.getenv('MAIL_DOMAIN', '85.131.251.224')
        self.BEACON_URL_BASE = os.getenv('BEACON_URL_BASE', 'http://85.131.251.224')
        
        # ユーザー・権限設定
        self.TRAINING_USER = os.getenv('TRAINING_USER', 'training')
        self.TRAINING_GROUP = os.getenv('TRAINING_GROUP', 'www-data')
        self.LOG_GROUP = os.getenv('LOG_GROUP', 'adm')
    
    def ensure_directories(self):
        """必要なディレクトリを作成"""
        directories = [
            self.TRAINING_DATA_DIR,
            self.TRAINING_ATTACHMENT_DIR,
            self.TRAINING_LOG_DIR,
            self.EMAIL_LOG_DIR,
            f"{self.TRAINING_DATA_DIR}/logs",
            f"{self.TRAINING_DATA_DIR}/Bak"
        ]
        
        for directory in directories:
            Path(directory).mkdir(parents=True, exist_ok=True)
    
    def get_data_file_path(self, filename):
        """データファイルのフルパスを取得"""
        return os.path.join(self.TRAINING_DATA_DIR, filename)
    
    def get_attachment_file_path(self, filename):
        """添付ファイルのフルパスを取得"""
        return os.path.join(self.TRAINING_ATTACHMENT_DIR, filename)
    
    def get_log_file_path(self, filename):
        """ログファイルのフルパスを取得"""
        return os.path.join(self.TRAINING_LOG_DIR, filename)
    
    def get_beacon_url(self, random_id):
        """ビーコンURLを生成"""
        return f"{self.BEACON_URL_BASE}/kunren-beacon-{random_id}.png"
    
    def get_link_url(self, random_id):
        """リンクURLを生成"""
        return f"{self.BEACON_URL_BASE}/link-{random_id}.html"

# グローバル設定インスタンス
config = TrainingConfig()

def load_environment():
    """環境変数ファイルを読み込み"""
    env_file = "/etc/training/training.env"
    if os.path.exists(env_file):
        with open(env_file, 'r') as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith('#') and '=' in line:
                    # exportを除去して環境変数として設定
                    if line.startswith('export '):
                        line = line[7:]  # 'export 'を除去
                    key, value = line.split('=', 1)
                    os.environ[key] = value
    
    # 設定を再読み込み
    config._load_config()
    config.ensure_directories()

if __name__ == "__main__":
    # テスト用
    load_environment()
    print("Training System Configuration:")
    print(f"  TRAINING_HOME: {config.TRAINING_HOME}")
    print(f"  DATA_DIR: {config.TRAINING_DATA_DIR}")
    print(f"  ATTACHMENT_DIR: {config.TRAINING_ATTACHMENT_DIR}")
    print(f"  LOG_DIR: {config.TRAINING_LOG_DIR}")
    print(f"  OPERATION_LOG: {config.OPERATION_LOG_FILE}")