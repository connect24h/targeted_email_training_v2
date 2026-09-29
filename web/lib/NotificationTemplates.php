<?php
/**
 * 通知の文面(C2、G35)。通知の種類ごとの件名と本文を、テナントが上書きできるようにする。
 *
 * - 既定の文面は、ここにある文字列(以前は各送信口に直書きしていたものを、そのまま移した)。
 *   テナントの上書き(notification_templates の行)がなければ既定で送るので、上書きのない時の文面は以前と1文字も変わらない。
 * - 差し込みは種類ごとに決めた変数({氏名} など)だけを、文字どおりに置き換える(式や条件は書けない)。
 *   値に「{...}」が入っていても、もう一度は置き換えない(1回の strtr)。
 * - 本文で、値が空の省略できる差し込み(期限、有効期限、アカウントの注記、組織名、問い合わせ先、送信日、件名、差出人)を含む行は、行ごと消す
 *   (期限のない配信の「締切:」の行など)。
 *   消した行の前後がどちらも空行なら、空行を1つにまとめる。件名は1行なので空のまま置き換える。
 * - 保存の時に、決まった一覧にない差し込みと、URL の差し込みを本文から消したもの(受講・回答・設定ができなくなる)を拒む。
 * - 件名の改行は保存でも差し込みの後でも取り除く(ヘッダインジェクションの対策。EduMailer::send も取り除く)。
 */
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/EduMailer.php';

final class NotificationTemplateException extends RuntimeException
{
}

final class NotificationTemplates
{
    public const SUBJECT_MAX = 200;
    public const BODY_MAX = 5000;

    /** テスト送信の上限: 1人あたり、この分数の間にこの回数まで。 */
    public const TEST_SEND_LIMIT = 5;
    public const TEST_SEND_WINDOW_MINUTES = 10;

    /** 本文の結びの定型(既定の文面で共通)。 */
    private const FOOTER = "※本メールは自動送信です。ご不明点は管理者へお問い合わせください。\n";

    /** 差し込みの説明(画面に出す)。 */
    private const VARIABLE_LABELS = [
        '氏名'               => '宛先の氏名（空の時は「ご担当者」）',
        '配信名'             => '教育の配信のタイトル',
        'アンケート名'       => 'アンケートのタイトル',
        '期限'               => '受講・回答の締切（ない時はその行を消す）',
        '受講URL'            => '受講のページの URL（本文から消せません）',
        '回答URL'            => '回答のページの URL（本文から消せません）',
        '設定URL'            => 'パスワード設定のページの URL（本文から消せません）',
        'メールアドレス'     => 'ログインに使うメールアドレス',
        '有効期限'           => 'リンクの有効期限（ない時はその行を消す）',
        'パスワードの決まり' => 'パスワードの決まりの説明',
        'マイページURL'      => '受講者のマイページの URL',
        'アカウントの注記'   => '管理画面と共通のアカウントの時だけ入る注記（ほかの時はその行を消す）',
        '組織名'             => 'テナント（組織）の名前',
        '問い合わせ先'       => '教育配信の画面で登録した社内の問い合わせ先（未設定ならその行を消す）',
        '送信日'             => '訓練のメールを送った日（ない時はその行を消す）',
        '種明かしURL'        => '訓練の種明かしのページの URL（本文から消せません）',
        '報告者'             => '不審メールを報告した人のアドレス',
        '件名'               => '報告されたメールの件名（先頭の120文字。ない時はその行を消す）',
        '差出人'             => '報告されたメールの差出人のアドレス（ない時はその行を消す）',
        '受信日時'           => '報告を受け取った日時',
        '管理画面URL'        => '管理画面の不審メールの一覧の URL',
    ];

    /** 値が空の時に、本文のその行を消す差し込み(ほかの差し込みは空のまま置き換える)。 */
    private const OPTIONAL_VARS = ['期限', '有効期限', 'アカウントの注記', '組織名', '問い合わせ先', '送信日', '件名', '差出人'];

    private const EDU_VARS = ['氏名', '配信名', '期限', '受講URL', '組織名', '問い合わせ先'];
    private const SURVEY_VARS = ['氏名', 'アンケート名', '期限', '回答URL', '組織名', '問い合わせ先'];
    private const ADMIN_VARS = ['氏名', '設定URL', 'メールアドレス', '有効期限', 'パスワードの決まり', '組織名', '問い合わせ先'];
    private const LEARNER_VARS = ['氏名', '設定URL', 'メールアドレス', '有効期限', 'パスワードの決まり', 'マイページURL',
        'アカウントの注記', '組織名', '問い合わせ先'];
    /** 種明かしメール(段D の D2)。訓練の名前は社内の呼び名なので差し込まない。 */
    private const REVEAL_VARS = ['氏名', '送信日', '種明かしURL', '組織名', '問い合わせ先'];
    /** 担当者への報告の通知(段D の D3)。報告されたメールの本文は入れない(件名と差出人だけ)。 */
    private const REPORT_NOTIFY_VARS = ['報告者', '件名', '差出人', '受信日時', '管理画面URL', '組織名'];

    /** 種類の一覧(画面の並び順)。 */
    public const KINDS = [
        'edu_invite', 'edu_followup_invite', 'edu_reminder', 'edu_reminder_auto',
        'survey_invite', 'survey_reminder',
        'learner_invite', 'learner_reset',
        'admin_invite', 'admin_reset',
        'reveal_failed', 'reveal_closed', 'reveal_reported', 'report_notify',
    ];

    /**
     * 種類ごとの定義。subject と body が既定の文面(以前の直書きと同じ文字列)。
     * @return array<string, array{label:string, used_by:string, variables:list<string>, required:list<string>, subject:string, body:string}>
     */
    public static function definitions(): array
    {
        $learnerTail = "\n{設定URL}\n\n"
            . "ログインに使うメールアドレス: {メールアドレス}\n"
            . "リンクの有効期限: {有効期限}（72時間。1回だけ使えます）\n"
            . "パスワードの決まり: {パスワードの決まり}\n"
            . "マイページ: {マイページURL}\n"
            . "{アカウントの注記}\n\n"
            . "お心当たりがない場合は、このメールを破棄してください。\n"
            . "※本メールは自動送信です。ご不明点は社内の担当者へお問い合わせください。\n";
        $adminTail = "\n{設定URL}\n\n"
            . "ログインに使うメールアドレス: {メールアドレス}\n"
            . "リンクの有効期限: {有効期限}（72時間。1回だけ使えます）\n"
            . "パスワードの決まり: {パスワードの決まり}\n\n"
            . "お心当たりがない場合は、このメールを破棄してください。\n"
            . self::FOOTER;
        $surveyBody = "{氏名} 様\n\n"
            . "アンケート「{アンケート名}」へのご協力をお願いします。\n"
            . "下記の URL から回答できます（ログイン不要）。\n\n"
            . "{回答URL}\n\n"
            . "回答の締切: {期限}\n\n"
            . self::FOOTER;

        return [
            'edu_invite' => [
                'label' => '教育の案内',
                'used_by' => '教育の配信を開始した時と、予約・毎月の配信の開始時の受講依頼（案内メールを送る配信だけ）',
                'variables' => self::EDU_VARS,
                'required' => ['受講URL'],
                'subject' => '【受講のご案内】{配信名}',
                'body' => "{氏名} 様\n\n"
                    . "セキュリティ教育「{配信名}」が配信されました。\n"
                    . "下記URLよりご受講ください（所要5〜10分・ログイン不要）。\n\n"
                    . "{受講URL}\n\n"
                    . self::FOOTER,
            ],
            'edu_followup_invite' => [
                'label' => '教育の案内（訓練の後の自動の割り当て）',
                'used_by' => '訓練で防衛に失敗した人への自動の割り当て（tet2-edu-enroll。案内メールを送る配信だけ）',
                'variables' => self::EDU_VARS,
                'required' => ['受講URL'],
                'subject' => '【受講のご案内】{配信名}',
                'body' => "{氏名} 様\n\n"
                    . "先日の標的型メール訓練の結果にもとづき、フォローアップ教育「{配信名}」をご案内します。\n"
                    . "訓練で気づけなかった点を短時間で確認できます。下記URLよりご受講ください（所要5〜10分・ログイン不要）。\n\n"
                    . "{受講URL}\n\n"
                    . self::FOOTER,
            ],
            'edu_reminder' => [
                'label' => '教育の催促（画面から送る）',
                'used_by' => '教育配信の画面の「催促」ボタン',
                'variables' => self::EDU_VARS,
                'required' => ['受講URL'],
                'subject' => '【受講のお願い】{配信名}',
                'body' => "{氏名} 様\n\n"
                    . "セキュリティ教育「{配信名}」が未受講です。\n"
                    . "下記URLよりご受講ください（所要5〜10分・ログイン不要）。\n\n"
                    . "{受講URL}\n\n"
                    . self::FOOTER,
            ],
            'edu_reminder_auto' => [
                'label' => '教育の催促（自動）',
                'used_by' => '未受講の人への自動の催促（tet2-edu-reminder）',
                'variables' => self::EDU_VARS,
                'required' => ['受講URL'],
                'subject' => '【受講のお願い(リマインド)】{配信名}',
                'body' => "{氏名} 様\n\n"
                    . "セキュリティ教育「{配信名}」が未受講です。\n"
                    . "お手数ですが下記URLよりご受講ください（所要5〜10分・ログイン不要）。\n\n"
                    . "{受講URL}\n\n"
                    . self::FOOTER,
            ],
            'survey_invite' => [
                'label' => 'アンケートの案内',
                'used_by' => 'アンケートの配信の「案内メールを送る」',
                'variables' => self::SURVEY_VARS,
                'required' => ['回答URL'],
                'subject' => '【アンケートのお願い】{アンケート名}',
                'body' => $surveyBody,
            ],
            'survey_reminder' => [
                'label' => 'アンケートの催促',
                'used_by' => '締切の2日前からの催促（survey_reminder）',
                'variables' => self::SURVEY_VARS,
                'required' => ['回答URL'],
                'subject' => '【回答のお願い（締切間近）】{アンケート名}',
                'body' => $surveyBody,
            ],
            'learner_invite' => [
                'label' => '受講者のマイページの招待',
                'used_by' => 'ユーザ管理の「マイページの招待を送る」',
                'variables' => self::LEARNER_VARS,
                'required' => ['設定URL'],
                'subject' => '【セキュリティ教育】マイページのパスワード設定のお願い',
                'body' => "{氏名} 様\n\n"
                    . "セキュリティ教育の受講者のマイページをご用意しました。\n"
                    . "マイページでは、受講する教育と回答するアンケート、ご自分の成績を確認でき、教育を受け直せます。\n"
                    . "下記の URL を開き、パスワードを設定してください。\n"
                    . $learnerTail,
            ],
            'learner_reset' => [
                'label' => '受講者のマイページのパスワード再設定',
                'used_by' => 'マイページの「パスワードを忘れた」と、管理者からの再送',
                'variables' => self::LEARNER_VARS,
                'required' => ['設定URL'],
                'subject' => '【セキュリティ教育】マイページのパスワード再設定のご案内',
                'body' => "{氏名} 様\n\n"
                    . "セキュリティ教育の受講者のマイページのパスワードの再設定を受け付けました。\n"
                    . "下記の URL を開き、新しいパスワードを設定してください。\n"
                    . $learnerTail,
            ],
            'admin_invite' => [
                'label' => '管理画面のユーザの招待',
                'used_by' => 'ユーザ管理の管理画面ユーザの招待メール',
                'variables' => self::ADMIN_VARS,
                'required' => ['設定URL'],
                'subject' => '【TET v2】管理画面のアカウントのパスワード設定のお願い',
                'body' => "{氏名} 様\n\n"
                    . "TET v2（標的型メール訓練・教育の管理画面）のアカウントが作られました。\n"
                    . "下記の URL を開き、パスワードを設定してください。\n"
                    . $adminTail,
            ],
            'admin_reset' => [
                'label' => '管理画面のユーザのパスワード再設定',
                'used_by' => 'ユーザ管理の管理画面ユーザのパスワード再設定のメール',
                'variables' => self::ADMIN_VARS,
                'required' => ['設定URL'],
                'subject' => '【TET v2】パスワード再設定のご案内',
                'body' => "{氏名} 様\n\n"
                    . "TET v2（標的型メール訓練・教育の管理画面）のパスワードの再設定を、管理者が受け付けました。\n"
                    . "下記の URL を開き、新しいパスワードを設定してください。\n"
                    . $adminTail,
            ],
            'reveal_failed' => [
                'label' => '種明かし（防衛に失敗した直後）',
                'used_by' => '訓練の「種明かしメール」で「失敗した直後」を選んだ訓練で、リンクを開いたか入力した本人にだけ送る（訓練の実施中）',
                'variables' => self::REVEAL_VARS,
                'required' => ['種明かしURL'],
                'subject' => '【ご確認ください】先ほどのメールは標的型メール訓練でした',
                'body' => "{氏名} 様\n\n"
                    . "先ほど開いたメールは、情報セキュリティの向上を目的とした標的型メール訓練のメールでした。\n"
                    . "実際の被害はありませんのでご安心ください。\n\n"
                    . "本物の攻撃メールでは、同じ操作で情報を盗まれたり、ウイルスに感染したりするおそれがあります。\n"
                    . "見分け方のポイントを、下記のページでご確認ください。\n\n"
                    . "{種明かしURL}\n\n"
                    . "訓練はまだ続いています。訓練の結果を正しく測るため、このメールのことは周りの方に話さないでください。\n"
                    . "問い合わせ先: {問い合わせ先}\n\n"
                    . self::FOOTER,
            ],
            'reveal_closed' => [
                'label' => '種明かし（訓練の終了後）',
                'used_by' => '訓練の「種明かしメール」で「訓練の終了後」を選んだ訓練を閉じた時に、選んだ範囲の対象者へ送る',
                'variables' => self::REVEAL_VARS,
                'required' => ['種明かしURL'],
                'subject' => '【標的型メール訓練】訓練の実施のご報告',
                'body' => "{氏名} 様\n\n"
                    . "先日お送りしたメールは、情報セキュリティの向上を目的とした標的型メール訓練のメールでした。\n"
                    . "訓練のメールを送った日: {送信日}\n"
                    . "訓練は終了しました。ご協力ありがとうございました。\n\n"
                    . "訓練のメールの見分け方のポイントを、下記のページでご確認ください。\n\n"
                    . "{種明かしURL}\n\n"
                    . "問い合わせ先: {問い合わせ先}\n\n"
                    . self::FOOTER,
            ],
            'reveal_reported' => [
                'label' => '種明かし（訓練のメールを報告した人へ）',
                'used_by' => '訓練の「種明かしメール」で「報告した人へ」を選んだ訓練を閉じた時に、訓練のメールを報告した人へ送る',
                'variables' => self::REVEAL_VARS,
                'required' => ['種明かしURL'],
                'subject' => '【標的型メール訓練】ご報告ありがとうございました',
                'body' => "{氏名} 様\n\n"
                    . "ご報告いただいたメールは、情報セキュリティの向上を目的とした標的型メール訓練のメールでした。\n"
                    . "訓練のメールを送った日: {送信日}\n"
                    . "不審なメールに気づいて報告していただいたことは、適切な対応です。ありがとうございました。\n\n"
                    . "訓練のメールの見分け方のポイントを、下記のページでご確認ください。\n\n"
                    . "{種明かしURL}\n\n"
                    . "問い合わせ先: {問い合わせ先}\n\n"
                    . self::FOOTER,
            ],
            'report_notify' => [
                'label' => '担当者への不審メールの報告の通知',
                'used_by' => '不審メールの画面の「担当者への通知」に登録したアドレスへ、報告用のアドレスに届いた不審メールの報告を知らせる',
                'variables' => self::REPORT_NOTIFY_VARS,
                'required' => [],
                'subject' => '【不審メールの報告】{報告者} から報告がありました',
                'body' => "社内の担当者 各位\n\n"
                    . "報告用のアドレスに、不審メールの報告が届きました。管理画面で内容を確認してください。\n\n"
                    . "報告者: {報告者}\n"
                    . "受信日時: {受信日時}\n"
                    . "件名: {件名}\n"
                    . "差出人: {差出人}\n\n"
                    . "{管理画面URL}\n\n"
                    . "このメールには、報告されたメールの本文と添付は入れていません。\n"
                    . self::FOOTER,
            ],
        ];
    }

    /** @return array{label:string, used_by:string, variables:list<string>, required:list<string>, subject:string, body:string} */
    public static function definition(string $kind): array
    {
        $defs = self::definitions();
        if (!isset($defs[$kind])) {
            throw new NotificationTemplateException('通知の種類が正しくありません');
        }
        return $defs[$kind];
    }

    /** 差し込みの説明。 */
    public static function variableLabel(string $name): string
    {
        return self::VARIABLE_LABELS[$name] ?? '';
    }

    /**
     * テナントの今の文面(上書きがあればそれ、なければ既定)。
     * $tenantId が null(システム管理者のアカウントなど)は既定。
     * @return array{subject:string, body:string, customized:bool, updated_by:?string, updated_at:?string}
     */
    public static function current(?int $tenantId, string $kind): array
    {
        $def = self::definition($kind);
        $row = null;
        if ($tenantId !== null) {
            try {
                $row = Db::one(
                    'SELECT subject, body, updated_by, updated_at FROM notification_templates WHERE tenant_id = ? AND kind = ?',
                    [$tenantId, $kind]
                );
            } catch (PDOException $e) {
                // migration の前(表がない)は上書きもないので、既定の文面で送る(送信を止めない)
                if (!str_contains($e->getMessage(), 'no such table')) {
                    throw $e;
                }
            }
        }
        if ($row === null) {
            return ['subject' => $def['subject'], 'body' => $def['body'], 'customized' => false,
                'updated_by' => null, 'updated_at' => null];
        }
        return ['subject' => (string) $row['subject'], 'body' => (string) $row['body'], 'customized' => true,
            'updated_by' => $row['updated_by'] !== null ? (string) $row['updated_by'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null];
    }

    /**
     * テナントの文面で件名と本文を作る。$vars は差し込みの名前 => 値。
     * 組織名と問い合わせ先は、渡されていなければテナントから引く。
     * @param array<string, string|null> $vars
     * @return array{subject:string, body:string}
     */
    public static function render(?int $tenantId, string $kind, array $vars): array
    {
        $tpl = self::current($tenantId, $kind);
        return self::renderText($kind, $tpl['subject'], $tpl['body'], self::withTenantVars($tenantId, $vars));
    }

    /**
     * 与えた件名と本文(下書きを含む)に差し込む。種類の一覧にない差し込みは置き換えない。
     * @param array<string, string|null> $vars
     * @return array{subject:string, body:string}
     */
    public static function renderText(string $kind, string $subject, string $body, array $vars): array
    {
        $def = self::definition($kind);
        $values = [];
        foreach ($def['variables'] as $name) {
            $values[$name] = self::value($name, $vars[$name] ?? null);
        }
        $map = [];
        foreach ($values as $name => $value) {
            $map['{' . $name . '}'] = $value;
        }
        // 件名: 1行。値の改行も含めて取り除く
        $renderedSubject = self::stripLineBreaks(strtr(self::stripLineBreaks($subject), $map));

        // 本文: 値が空の差し込みを含む行は消す
        $lines = explode("\n", str_replace("\r\n", "\n", $body));
        $out = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (self::lineHasEmptyValue($line, $values)) {
                // 消した行の前後がどちらも空行なら、後ろの空行を落として1つにまとめる
                if ($out !== [] && end($out) === '' && $i + 1 < $count && $lines[$i + 1] === '') {
                    $i++;
                }
                continue;
            }
            $out[] = strtr($line, $map);
        }
        return ['subject' => $renderedSubject, 'body' => implode("\n", $out)];
    }

    /**
     * 保存できる件名と本文か確かめる。整えた件名と本文を返し、だめなら例外(理由は画面にそのまま出す)。
     * @return array{subject:string, body:string}
     */
    public static function validate(string $kind, string $subject, string $body): array
    {
        $def = self::definition($kind);
        $subject = trim(self::stripLineBreaks($subject));
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        if ($subject === '') {
            throw new NotificationTemplateException('件名を入力してください');
        }
        if (mb_strlen($subject) > self::SUBJECT_MAX) {
            throw new NotificationTemplateException('件名は' . self::SUBJECT_MAX . '文字以内にしてください');
        }
        if (trim($body) === '') {
            throw new NotificationTemplateException('本文を入力してください');
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            throw new NotificationTemplateException('本文は' . self::BODY_MAX . '文字以内にしてください');
        }
        $unknown = [];
        foreach (['件名' => $subject, '本文' => $body] as $where => $text) {
            foreach (self::placeholders($text) as $name) {
                if (!in_array($name, $def['variables'], true)) {
                    $unknown[] = $where . 'の{' . $name . '}';
                }
            }
        }
        if ($unknown !== []) {
            throw new NotificationTemplateException('この通知で使えない差し込みがあります: ' . implode('、', array_unique($unknown))
                . '（使えるのは ' . implode(' ', array_map(static fn(string $n): string => '{' . $n . '}', $def['variables'])) . '）');
        }
        foreach ($def['required'] as $name) {
            if (!str_contains($body, '{' . $name . '}')) {
                throw new NotificationTemplateException('本文に {' . $name . '} を入れてください（ないと受け取った人が先へ進めません）');
            }
        }
        return ['subject' => $subject, 'body' => $body];
    }

    /** テナントの上書きを保存する(既定と同じ文面でも行として持つ)。 */
    public static function save(int $tenantId, string $kind, string $subject, string $body, ?string $updatedBy): array
    {
        $clean = self::validate($kind, $subject, $body);
        Db::run(
            "INSERT INTO notification_templates (tenant_id, kind, subject, body, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, datetime('now','localtime'))
             ON CONFLICT (tenant_id, kind) DO UPDATE SET subject = excluded.subject, body = excluded.body,
                 updated_by = excluded.updated_by, updated_at = excluded.updated_at",
            [$tenantId, $kind, $clean['subject'], $clean['body'], $updatedBy]
        );
        return $clean;
    }

    /** テナントの上書きを消して既定に戻す。消した行があれば true。 */
    public static function reset(int $tenantId, string $kind): bool
    {
        self::definition($kind);
        return Db::run('DELETE FROM notification_templates WHERE tenant_id = ? AND kind = ?', [$tenantId, $kind]) > 0;
    }

    /**
     * プレビューとテスト送信の見本の値。URL は見本のトークンで、開いても受講・設定はできない。
     * @return array<string, string>
     */
    public static function sampleValues(?int $tenantId, string $kind, string $email = 'sample@example.test'): array
    {
        require_once __DIR__ . '/UserPasswordTokens.php';
        $vars = [
            '氏名' => '見本 太郎',
            '配信名' => '見本の教育（標的型メールの見分け方）',
            'アンケート名' => '見本のアンケート',
            '期限' => date('Y-m-d', time() + 7 * 86400) . ' 17:00',
            '受講URL' => EduMailer::takeUrl('sample-token-for-preview'),
            '回答URL' => EduMailer::baseUrl() . '/survey.php?token=sample-token-for-preview',
            '設定URL' => UserPasswordTokens::url('sample-token-for-preview', str_starts_with($kind, 'learner_') ? 'my' : 'admin'),
            'メールアドレス' => $email,
            '有効期限' => date('Y-m-d H:i', time() + 72 * 3600),
            'パスワードの決まり' => PasswordPolicy::DESCRIPTION,
            'マイページURL' => UserPasswordTokens::learnerBaseUrl() . '/my.php',
            'アカウントの注記' => str_starts_with($kind, 'learner_')
                ? '※このパスワードは管理画面のパスワードと共通です（同じアカウントです）。' : '',
            '送信日' => date('Y-m-d', time() - 3 * 86400),
            '種明かしURL' => EduMailer::baseUrl() . '/reveal_view.php?token=sample-token-for-preview',
            '報告者' => 'reporter@example.test',
            '件名' => '【見本】請求書の確認のお願い',
            '差出人' => 'sender@example.test',
            '受信日時' => date('Y-m-d H:i'),
            '管理画面URL' => UserPasswordTokens::adminBaseUrl() . '/#suspiciousMails',
        ];
        return self::withTenantVars($tenantId, $vars);
    }

    /**
     * テスト送信の回数の上限に達しているか(直近 TEST_SEND_WINDOW_MINUTES 分の監査ログを数える)。
     */
    public static function testSendLimited(int $userId): bool
    {
        $row = Db::one(
            "SELECT COUNT(*) AS c FROM audit_log
             WHERE action = 'notification_template.test_send' AND user_id = ?
               AND occurred_at >= datetime('now','localtime','-" . self::TEST_SEND_WINDOW_MINUTES . " minutes')",
            [$userId]
        );
        return $row !== null && (int) $row['c'] >= self::TEST_SEND_LIMIT;
    }

    /**
     * 教育のメール(案内・催促)の差し込みの値。
     * @return array<string, string>
     */
    public static function eduVars(string $name, string $title, string $token, ?string $deadline): array
    {
        return [
            '氏名' => $name,
            '配信名' => $title,
            '受講URL' => EduMailer::takeUrl($token),
            '期限' => self::formatDeadline($deadline),
        ];
    }

    /** 期限の表示('YYYY-MM-DD HH:MM' まで。日付だけならそのまま)。なければ空。 */
    public static function formatDeadline(?string $deadline): string
    {
        $deadline = trim((string) $deadline);
        return $deadline === '' ? '' : substr($deadline, 0, 16);
    }

    /** 文字列に含まれる差し込みの名前(重複なし)。 */
    public static function placeholders(string $text): array
    {
        preg_match_all('/\{([^{}\r\n]*)\}/u', $text, $m);
        return array_values(array_unique($m[1]));
    }

    /** @param array<string, string|null> $vars */
    private static function withTenantVars(?int $tenantId, array $vars): array
    {
        if ($tenantId !== null && (!array_key_exists('組織名', $vars) || !array_key_exists('問い合わせ先', $vars))) {
            $t = Db::one('SELECT name, edu_contact FROM tenants WHERE id = ?', [$tenantId]);
            $vars += ['組織名' => (string) ($t['name'] ?? ''), '問い合わせ先' => (string) ($t['edu_contact'] ?? '')];
        }
        return $vars;
    }

    /** 値は渡されたまま使う(以前の直書きと同じ文字列にするため)。氏名だけは前後の空白を除き、空なら「ご担当者」。 */
    private static function value(string $name, ?string $value): string
    {
        $value = (string) $value;
        if ($name === '氏名') {
            $value = trim($value);
            return $value !== '' ? $value : 'ご担当者';
        }
        return $value;
    }

    /** @param array<string,string> $values */
    private static function lineHasEmptyValue(string $line, array $values): bool
    {
        foreach (self::placeholders($line) as $name) {
            if (in_array($name, self::OPTIONAL_VARS, true) && array_key_exists($name, $values) && $values[$name] === '') {
                return true;
            }
        }
        return false;
    }

    private static function stripLineBreaks(string $s): string
    {
        return str_replace(["\r", "\n"], '', $s);
    }
}
