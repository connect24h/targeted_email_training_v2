<?php
/**
 * アンケートの雛形(TET v2 独自の文面)。作成画面で選ぶと、下書きとして複製される。
 * show_if の question_index は同じ雛形内の0始まりの設問番号。
 */
declare(strict_types=1);

final class SurveyTemplates
{
    /** @return array<string, array<string,mixed>> key => 雛形 */
    public static function all(): array
    {
        return [
            'after_training' => [
                'label' => '訓練後の振り返り',
                'title' => '標的型メール訓練の振り返りアンケート',
                'description' => '先日の標的型メール訓練について伺います。回答は今後の訓練と教育の改善に使います。所要時間は3分ほどです。',
                'is_anonymous' => false,
                'questions' => [
                    ['section' => '訓練メールへの対応', 'question_type' => 'single', 'is_required' => true,
                        'title' => '訓練メールを受け取ったときの対応に最も近いものを選んでください。',
                        'options' => ['開かずに報告した', '開いたがリンクや添付は開かず、報告した', 'リンクや添付を開いた後に報告した', 'リンクや添付を開き、報告しなかった', '気づかなかった、または覚えていない']],
                    ['section' => '訓練メールへの対応', 'question_type' => 'multiple', 'is_required' => false,
                        'title' => '不審だと感じた点があれば、すべて選んでください。',
                        'options' => ['差出人の名前やアドレス', '件名', '本文の日本語や言い回し', 'リンク先の URL', '添付ファイルの名前や形式', '心当たりのない用件', '特になかった']],
                    ['section' => '報告について', 'question_type' => 'single', 'is_required' => true,
                        'title' => '不審なメールを受け取ったときの報告先を知っていましたか。',
                        'options' => ['知っていた', '知らなかった']],
                    ['section' => '報告について', 'question_type' => 'multiple', 'is_required' => false,
                        'title' => '報告しなかった理由に当てはまるものを選んでください。',
                        'options' => ['報告の方法が分からなかった', '手間がかかると感じた', '自分で削除すれば十分だと思った', '訓練だと分かったので報告しなかった', 'その他'],
                        'show_if' => ['question_index' => 0, 'option' => 3]],
                    ['section' => '今後に向けて', 'question_type' => 'single', 'is_required' => true,
                        'title' => '次に不審なメールを受け取ったら、どう対応しますか。',
                        'options' => ['開かずに報告する', '内容を確かめてから判断する', '削除する', '分からない']],
                    ['section' => '今後に向けて', 'question_type' => 'text', 'is_required' => false,
                        'title' => '訓練や教育についてのご意見があれば、自由にお書きください。'],
                ],
            ],
            'after_education' => [
                'label' => '教育後の理解確認',
                'title' => 'セキュリティ教育の理解確認アンケート',
                'description' => '受講したセキュリティ教育の内容について、理解の程度を伺います。成績評価には使いません。',
                'is_anonymous' => true,
                'questions' => [
                    ['question_type' => 'single', 'is_required' => true,
                        'title' => '教育の内容はどの程度理解できましたか。',
                        'options' => ['よく理解できた', 'おおむね理解できた', 'あまり理解できなかった', '理解できなかった']],
                    ['question_type' => 'single', 'is_required' => true,
                        'title' => '教育の内容は、日々の業務に役立つと感じましたか。',
                        'options' => ['役立つ', 'ある程度役立つ', 'あまり役立たない', '役立たない']],
                    ['question_type' => 'multiple', 'is_required' => false,
                        'title' => 'もっと詳しく知りたいテーマを選んでください。',
                        'options' => ['不審メールの見分け方', 'パスワードと多要素認証', '生成 AI の安全な使い方', '社外や在宅での注意点', '事故が起きたときの連絡手順']],
                    ['question_type' => 'text', 'is_required' => false,
                        'title' => '分かりにくかった点や、改善してほしい点があればお書きください。'],
                ],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
