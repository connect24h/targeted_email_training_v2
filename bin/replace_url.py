#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import pandas as pd
import os
import configparser
from datetime import datetime

def log_operation(message):
    """統合動作ログに記録"""
    log_file = '/opt/training/logs/operation.log'
    timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    with open(log_file, 'a', encoding='utf-8') as f:
        f.write(f"[{timestamp}] [replace_url] {message}\n")

def get_beacon_url_base():
    """config.iniからビーコンURLベースを取得"""
    cfg = configparser.ConfigParser()
    config_path = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'config.ini')
    if os.path.exists(config_path):
        cfg.read(config_path)
        return cfg.get('server', 'beacon_url_base', fallback='http://85.131.251.224').rstrip('/')
    return 'http://85.131.251.224'

def replace_urls_in_list(data_dir='/opt/training/bin/data'):
    log_operation("URL差し替えプログラム開始")
    
    try:
        # ファイルパスの定義
        list_csv_path = os.path.join(data_dir, 'list.csv')
        url_csv_path = os.path.join(data_dir, 'URL.csv')
        
        # CSVファイル読み込み
        list_df = pd.read_csv(list_csv_path, encoding='utf-8')

        # URL.csv: ヘッダー行の有無を自動判定して読み込み
        url_df_raw = pd.read_csv(url_csv_path, encoding='utf-8', header=0)
        first_col = str(url_df_raw.columns[0]).strip()
        # 1列目が "件名定型文No", "No.", "No" 等の場合はヘッダー付き
        if first_col in ('件名定型文No', '項番', 'No.', 'No', 'no', 'no.', 'NO', 'NO.'):
            url_df = url_df_raw.rename(columns={url_df_raw.columns[0]: '件名定型文No', url_df_raw.columns[1]: 'URL'})
        else:
            # ヘッダーなし（1行目からデータ）の場合は再読み込み
            url_df = pd.read_csv(url_csv_path, encoding='utf-8', header=None, names=['件名定型文No', 'URL'])
        
        log_operation(f"list.csv読み込み: {len(list_df)}行")
        log_operation(f"URL.csv読み込み: {len(url_df)}行")
        
        # バックアップ作成
        backup_path = list_csv_path + '.backup_' + datetime.now().strftime('%Y%m%d_%H%M%S')
        list_df.to_csv(backup_path, index=False, encoding='utf-8')
        log_operation(f"バックアップ作成: {backup_path}")
        
        # 差し替え処理カウンタ
        replacement_count = 0
        
        # 1行単位で処理
        for index, row in list_df.iterrows():
            kenmei_no = row['件名定型文No']
            current_url = row['本文差し込み1 #$1$#']
            
            # URL.csvから対応するURLを検索
            url_match = url_df[url_df['件名定型文No'] == kenmei_no]
            
            if not url_match.empty:
                new_base_url = url_match.iloc[0]['URL']
                
                # 現在のURLからベースURL部分を新しいベースURLに置換
                beacon_base = get_beacon_url_base() + '/'
                if pd.notna(current_url) and beacon_base in str(current_url):
                    new_url = str(current_url).replace(beacon_base, new_base_url)
                    list_df.at[index, '本文差し込み1 #$1$#'] = new_url
                    
                    log_operation(f"行{index+1}: 件名定型文No{kenmei_no} - URL差し替え完了")
                    log_operation(f"  旧: {current_url}")
                    log_operation(f"  新: {new_url}")
                    replacement_count += 1
                else:
                    log_operation(f"行{index+1}: 件名定型文No{kenmei_no} - 対象URL文字列なし（スキップ）")
            else:
                log_operation(f"行{index+1}: 件名定型文No{kenmei_no} - URL.csvに対応なし（スキップ）")
        
        # 更新されたCSVファイルを保存
        list_df.to_csv(list_csv_path, index=False, encoding='utf-8')
        
        log_operation(f"URL差し替えプログラム完了 - 処理対象: {replacement_count}件")
        print(f"URL差し替えが完了しました。")
        print(f"処理対象: {replacement_count}件")
        print(f"バックアップファイル: {backup_path}")
        
    except Exception as e:
        error_msg = f"エラー発生: {str(e)}"
        log_operation(error_msg)
        print(error_msg)
        raise

def main():
    import argparse
    
    # コマンドライン引数のパーシング
    parser = argparse.ArgumentParser(description='URL差し替えプログラム')
    parser.add_argument('--data-dir', default='/opt/training/bin/data',
                        help='データディレクトリのパス')
    args = parser.parse_args()
    
    replace_urls_in_list(args.data_dir)

if __name__ == "__main__":
    main()