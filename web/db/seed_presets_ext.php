<?php declare(strict_types=1);
/**
 * TET v2 共有プリセット拡充（第2弾）。
 *
 * v1 の実運用テンプレートのパターン（アンケート型・通知型・ファイル共有型）を、
 * 実在の個人情報を排した汎用テンプレートとして共有プリセット(tenant_id NULL)に追加する。
 * プレースホルダ: #$1$#=訓練リンク / #$2$#=氏名 / #$3$#=任意 / #$6$#=送信先アドレス。
 *
 * 冪等: 同名(kind,name)の共有プリセットが既にあればスキップ。--commit なしは DRY-RUN。
 * 使い方: php seed_presets_ext.php [--commit]
 */

require_once __DIR__ . '/../lib/Db.php';

/** @return list<array{kind:string,name:string,format:string,content:string,auth_flag:?int}> */
function ext_presets(): array
{
    return [
        // ---- subject（件名）----
        ['kind' => 'subject', 'name' => '【ご協力のお願い】社内アンケートの実施', 'format' => 'text', 'auth_flag' => null,
         'content' => '【ご協力のお願い】業務改善に関する簡単なアンケート'],
        ['kind' => 'subject', 'name' => '【重要】アカウント情報の確認について', 'format' => 'text', 'auth_flag' => null,
         'content' => '【重要】アカウント情報のご確認をお願いいたします'],
        ['kind' => 'subject', 'name' => '【社内通知】福利厚生に関するご案内', 'format' => 'text', 'auth_flag' => null,
         'content' => '【社内通知】新しい福利厚生制度のご案内'],
        ['kind' => 'subject', 'name' => '経費精算システム更新のお知らせ', 'format' => 'text', 'auth_flag' => null,
         'content' => '経費精算システム更新に伴うログイン確認のお願い'],

        // ---- body（本文）----
        ['kind' => 'body', 'name' => 'アンケート依頼（フォーム誘導）', 'format' => 'text', 'auth_flag' => null,
         'content' => "#\$2\$# さん\n\nお疲れ様です。総務部より、業務環境の改善を目的とした簡単なアンケートを実施いたします。\n\n下記フォームより、2〜3分程度でご回答いただけます。\n\n▼アンケートフォーム\n#\$1\$#\n\n※ご回答は今週金曜日までにお願いいたします。\n※個人情報の入力は不要です。\n\n総務部"],
        ['kind' => 'body', 'name' => '福利厚生案内（サインイン誘導）', 'format' => 'text', 'auth_flag' => null,
         'content' => "各位\n\nこの度、従業員向けの新しい福利厚生制度を開始いたします。\n\n以下のリンクよりアクセスし、サインイン後に内容をご確認ください。\n\n▼福利厚生ポータル\n#\$1\$#\n\nご不明な点は人事部までお問い合わせください。\n\n人事部"],
        ['kind' => 'body', 'name' => '経費システム更新（再ログイン要求）', 'format' => 'text', 'auth_flag' => null,
         'content' => "#\$2\$# さん\n\n経費精算システムのアップデートを実施いたしました。\nセキュリティ強化のため、お手数ですが再度ログインをお願いいたします。\n\n▼経費精算システム\n#\$1\$#\n\n※ログインされない場合、月末の精算処理に影響する可能性があります。\n\n経理部"],

        // ---- phish_login（偽ログイン）----
        ['kind' => 'phish_login', 'name' => '偽ログイン（Google Workspace）', 'format' => 'html', 'auth_flag' => 0,
         'content' => "<!DOCTYPE html><html lang=\"ja\"><head><meta charset=\"UTF-8\"><title>ログイン</title></head><body style=\"font-family:Roboto,Arial,sans-serif;background:#fff;text-align:center;padding-top:60px\"><div style=\"max-width:360px;margin:0 auto;border:1px solid #dadce0;border-radius:8px;padding:40px\"><h2 style=\"font-weight:400\">ログイン</h2><p>お使いのアカウントで続行</p><input type=\"email\" placeholder=\"メールアドレス\" value=\"#\$6\$#\" style=\"width:100%;padding:12px;margin:8px 0;border:1px solid #dadce0;border-radius:4px\"><input type=\"password\" placeholder=\"パスワード\" style=\"width:100%;padding:12px;margin:8px 0;border:1px solid #dadce0;border-radius:4px\"><button style=\"background:#1a73e8;color:#fff;border:none;padding:10px 24px;border-radius:4px;float:right;margin-top:12px\">次へ</button></div></body></html>"],

        // ---- debrief（ネタバラシ）----
        ['kind' => 'debrief', 'name' => 'ネタバラシ（標準・前向き）', 'format' => 'html', 'auth_flag' => null,
         'content' => "<!DOCTYPE html><html lang=\"ja\"><head><meta charset=\"UTF-8\"><title>訓練のお知らせ</title></head><body style=\"font-family:sans-serif;max-width:640px;margin:40px auto;padding:0 16px;line-height:1.8;color:#222\"><h2 style=\"color:#1b3a5b\">これは標的型攻撃メールの訓練でした</h2><p>このメールは、実際の標的型攻撃メールを模した<strong>訓練</strong>です。リンクを開いた・情報を入力したことによる実害はありません。ご安心ください。</p><h3 style=\"color:#2c6fbb\">今回学べるポイント</h3><ul><li>送信元アドレスが正規のものか確認する</li><li>リンク先URLにマウスを重ねて遷移先を確かめる</li><li>ログイン情報の入力を求められたら一度立ち止まる</li><li>少しでも不審に感じたら情報システム部門へ報告する</li></ul><p>気づけたことも、気づけなかったことも、次に活かせば大きな一歩です。日頃からの注意を、これからもお願いいたします。</p><p style=\"color:#666;font-size:14px\">情報セキュリティ担当</p></body></html>"],

        // ---- elearning（eラーニング誘導）----
        ['kind' => 'elearning', 'name' => 'eラーニング誘導（確認クイズ）', 'format' => 'html', 'auth_flag' => null,
         'content' => "<!DOCTYPE html><html lang=\"ja\"><head><meta charset=\"UTF-8\"><title>フォローアップ学習</title></head><body style=\"font-family:sans-serif;max-width:640px;margin:40px auto;padding:0 16px;line-height:1.8\"><h2 style=\"color:#1b3a5b\">フォローアップ学習のご案内</h2><p>先日の訓練を踏まえ、標的型攻撃メールへの対処を復習しましょう。以下のミニクイズで理解度を確認できます。</p><ol><li>不審なメールのリンクを開いてしまった。まず何をすべき？<br><small>→ 情報システム部門へ速やかに報告する</small></li><li>ログイン画面で認証情報を入力した。どうする？<br><small>→ 直ちにパスワードを変更し、報告する</small></li><li>添付ファイルを開く前に確認すべきことは？<br><small>→ 送信元の正当性と、心当たりのある内容かどうか</small></li></ol><p>継続的な学習が、組織全体の防御力を高めます。</p></body></html>"],
    ];
}

$commit = in_array('--commit', $_SERVER['argv'] ?? [], true);
$presets = ext_presets();

$existing = [];
foreach (Db::all('SELECT kind, name FROM templates WHERE is_preset = 1 AND tenant_id IS NULL', []) as $row) {
    $existing[$row['kind'] . '|' . $row['name']] = true;
}

$toAdd = array_values(array_filter($presets, static fn ($p) => !isset($existing[$p['kind'] . '|' . $p['name']])));
$mode = $commit ? 'COMMIT' : 'DRY-RUN';
echo "=== プリセット拡充 [$mode] ===\n";
echo '定義: ' . count($presets) . " 件 / 追加予定: " . count($toAdd) . " 件（既存同名はskip）\n";
foreach ($toAdd as $p) {
    echo "  + [{$p['kind']}] {$p['name']}\n";
}

if (!$commit) {
    echo "\n[DRY-RUN] DB 未変更。実行するには --commit を付けてください。\n";
    exit(0);
}

$n = 0;
Db::tx(function () use ($toAdd, &$n): void {
    foreach ($toAdd as $p) {
        Db::run(
            'INSERT INTO templates (tenant_id, kind, name, lang, format, content, auth_flag, is_preset)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, 1)',
            [$p['kind'], $p['name'], 'ja', $p['format'], $p['content'], $p['auth_flag']]
        );
        $n++;
    }
});
echo "\n[COMMIT] $n 件の共有プリセットを追加しました。\n";
