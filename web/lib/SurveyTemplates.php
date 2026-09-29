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
            // 従業員向けの雛形(段D の D6)。いつ使っても差し支えない中立の文面にし、今後の取り組みを予告する言葉は入れない。
            'incident_experience' => [
                'label' => 'ヒヤリとした経験',
                'title' => '情報セキュリティでヒヤリとした経験についてのアンケート',
                'description' => '業務の中で、情報セキュリティについてヒヤリとした経験を伺います。回答は社内の対策の見直しに使い、個人の評価には使いません。所要時間は3分ほどです。',
                'is_anonymous' => true,
                'questions' => [
                    ['question_type' => 'single', 'is_required' => true,
                        'title' => 'この1年ほどの間に、情報セキュリティについてヒヤリとしたことはありますか。',
                        'options' => ['ある', 'ない', '分からない']],
                    ['question_type' => 'multiple', 'is_required' => false, 'allow_other' => true,
                        'title' => 'ヒヤリとしたのはどのような場面でしたか。当てはまるものをすべて選んでください。',
                        'options' => ['メールの宛先を間違えそうになった', '添付ファイルを間違えそうになった', '心当たりのないメールやメッセージを開いた',
                            'パソコンやスマートフォン、書類を置き忘れた', '画面や会話を社外の人に見られそうになった', 'パスワードを人に教えそうになった'],
                        'show_if' => ['question_index' => 0, 'option' => 0]],
                    ['question_type' => 'single', 'is_required' => false,
                        'title' => 'そのとき、社内の担当者へ相談や報告をしましたか。',
                        'options' => ['した', 'しなかった', '相談先が分からなかった'],
                        'show_if' => ['question_index' => 0, 'option' => 0]],
                    ['question_type' => 'text', 'is_required' => false,
                        'title' => '同じことが起きないように、あると助かる仕組みや情報があればお書きください。'],
                ],
            ],
            'remote_work' => [
                'label' => '社外や在宅での働き方',
                'title' => '社外や在宅で仕事をするときの情報の扱いについてのアンケート',
                'description' => '在宅や外出先で仕事をするときの環境と、情報の扱いについて伺います。回答は社内の決まりや支援の見直しに使います。所要時間は3分ほどです。',
                'is_anonymous' => true,
                'questions' => [
                    ['question_type' => 'single', 'is_required' => true,
                        'title' => '在宅や外出先で仕事をすることはありますか。',
                        'options' => ['週に1日以上ある', '月に数回ある', 'ほとんどない']],
                    ['question_type' => 'multiple', 'is_required' => false, 'allow_other' => true,
                        'title' => '社外で仕事をする場所を、すべて選んでください。',
                        'options' => ['自宅', 'カフェや飲食店', '移動中の電車や新幹線', 'ホテル', '取引先のオフィス'],
                        'show_if' => ['question_index' => 0, 'option' => 0]],
                    ['question_type' => 'single', 'is_required' => false,
                        'title' => '社外でインターネットにつなぐときに、主に使うものを選んでください。',
                        'options' => ['会社が用意した回線や端末', '自宅の Wi-Fi', 'お店や施設の無料の Wi-Fi', 'スマートフォンのテザリング', '分からない']],
                    ['question_type' => 'multiple', 'is_required' => false,
                        'title' => '社外で仕事をするときに気を付けていることを、すべて選んでください。',
                        'options' => ['画面を人に見られない位置で作業する', '席を離れるときは画面をロックする', '通話で社外秘の話をしない',
                            '書類を持ち出さない、または持ち帰る', '特にない']],
                    ['question_type' => 'text', 'is_required' => false,
                        'title' => '社外で仕事をするときに困っていることがあればお書きください。'],
                ],
            ],
            'personal_data' => [
                'label' => '個人情報の取り扱い',
                'title' => '個人情報の取り扱いについてのアンケート',
                'description' => '業務で扱う個人情報（お客さまや従業員の氏名、連絡先など）の扱い方について伺います。回答は社内の決まりと教育の見直しに使います。所要時間は3分ほどです。',
                'is_anonymous' => true,
                'questions' => [
                    ['question_type' => 'single', 'is_required' => true,
                        'title' => '業務で個人情報を扱うことはありますか。',
                        'options' => ['毎日のようにある', 'ときどきある', 'ほとんどない']],
                    ['question_type' => 'multiple', 'is_required' => false, 'allow_other' => true,
                        'title' => '個人情報をどのような形で扱いますか。当てはまるものをすべて選んでください。',
                        'options' => ['紙の書類', '表計算のファイル', '社内のシステムや顧客の管理の画面', 'メールの本文や添付ファイル', 'クラウドの共有フォルダ']],
                    ['question_type' => 'single', 'is_required' => true,
                        'title' => '個人情報を社外へ送るときの社内の決まり（暗号化や上長の確認など）を知っていますか。',
                        'options' => ['よく知っている', 'だいたい知っている', 'あまり知らない', '知らない']],
                    ['question_type' => 'single', 'is_required' => true,
                        'title' => '個人情報をなくしたり、誤って送ったりした時の連絡先を知っていますか。',
                        'options' => ['知っている', '知らない']],
                    ['question_type' => 'text', 'is_required' => false,
                        'title' => '個人情報の扱いで迷うことや、分かりにくい決まりがあればお書きください。'],
                ],
            ],
            'training_feedback' => [
                'label' => '研修の感想',
                'title' => '情報セキュリティの研修についてのアンケート',
                'description' => '受けた情報セキュリティの研修について、感想と今後の希望を伺います。回答は研修の内容と進め方の改善に使います。所要時間は2分ほどです。',
                'is_anonymous' => true,
                'questions' => [
                    ['question_type' => 'single', 'is_required' => true,
                        'title' => '研修の長さはどうでしたか。',
                        'options' => ['長すぎた', 'ちょうどよかった', '短すぎた']],
                    ['question_type' => 'single', 'is_required' => true,
                        'title' => '研修の内容は、自分の業務に関係があると感じましたか。',
                        'options' => ['とても関係がある', 'ある程度関係がある', 'あまり関係がない', '関係がない']],
                    ['question_type' => 'multiple', 'is_required' => false, 'allow_other' => true,
                        'title' => '研修の形式で、受けやすいものをすべて選んでください。',
                        'options' => ['短い動画', 'スライドを読む教材', '確認のクイズ', '集合研修', '実際の事例の紹介']],
                    ['question_type' => 'text', 'is_required' => false,
                        'title' => '研修についてのご意見や、取り上げてほしいテーマがあればお書きください。'],
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
