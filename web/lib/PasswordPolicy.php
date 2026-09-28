<?php
/**
 * パスワードの決まり(全テナント共通)。
 *
 * - 12文字以上
 * - 英大文字、英小文字、数字、記号(英数字以外)のうち3種以上
 * - 72バイト以内(PASSWORD_DEFAULT の bcrypt は72バイトより後ろを黙って捨てるため)
 *
 * ユーザの作成、パスワードの変更、パスワード設定の画面、テナント作成の時の最初の管理者のすべてで
 * この関数だけを使う。既存のユーザのパスワードは変えない(次に変える時から適用する)。
 */
declare(strict_types=1);

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MIN_CLASSES = 3;
    public const MAX_BYTES = 72;

    /** 画面に出す決まりの説明。 */
    public const DESCRIPTION = '12文字以上で、英大文字・英小文字・数字・記号のうち3種類以上を含めてください';

    /** 決まりに合わなければ理由(日本語)、合えば null。 */
    public static function violation(string $password): ?string
    {
        if (mb_strlen($password, 'UTF-8') < self::MIN_LENGTH) {
            return 'パスワードは' . self::MIN_LENGTH . '文字以上にしてください';
        }
        if (strlen($password) > self::MAX_BYTES) {
            return 'パスワードが長すぎます（半角で' . self::MAX_BYTES . '文字以内）';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $password) === 1) {
            return 'パスワードに制御文字は使えません';
        }
        if (self::classCount($password) < self::MIN_CLASSES) {
            return 'パスワードには英大文字・英小文字・数字・記号のうち3種類以上を含めてください';
        }
        return null;
    }

    /** 含まれる文字の種類の数(英大文字、英小文字、数字、記号)。 */
    public static function classCount(string $password): int
    {
        $count = 0;
        foreach (['/[A-Z]/', '/[a-z]/', '/[0-9]/', '/[^A-Za-z0-9]/'] as $pattern) {
            if (preg_match($pattern, $password) === 1) {
                $count++;
            }
        }
        return $count;
    }
}
