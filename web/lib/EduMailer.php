<?php
/**
 * 教育受講メール送信(受講依頼・催促)。
 * ローカル Postfix(localhost:25) に直接投函する。フィッシング訓練の send_email.py とは別系統
 * (あちらは data-dir/koban 駆動の一括送信。こちらは個別トークンURLの案内メール)。
 *
 * 受講URLのベースは環境変数 TET2_EDU_BASE_URL で上書き可。既定は受講者ポータル sat.cojp.online。
 * 差出人は TET2_EDU_MAIL_FROM で上書き可。既定は no-reply@cojp.online。
 * 管理画面ユーザの招待・パスワード再設定のメール(UserPasswordTokens)もこの送信口を使う。
 * テストでは useTransport() か TET2_EDU_MAIL_DISABLE=1、ブラウザの E2E では TET2_MAIL_OUTBOX_DIR で投函を止める。
 */
declare(strict_types=1);

final class EduMailer
{
    private const DEFAULT_BASE_URL  = 'https://sat.cojp.online';
    private const DEFAULT_MAIL_FROM = 'no-reply@cojp.online';

    /** @var null|callable(string,string,string):bool 送信口の差し替え(テスト用)。null なら SMTP へ投函する */
    private static $transport = null;

    /**
     * 送信口を差し替える。テストで「送信が何回呼ばれたか」を SMTP なしで数えるために使う。
     * null を渡すと SMTP への投函に戻る。
     */
    public static function useTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    public static function baseUrl(): string
    {
        $env = getenv('TET2_EDU_BASE_URL');
        return rtrim(($env !== false && $env !== '') ? $env : self::DEFAULT_BASE_URL, '/');
    }

    public static function mailFrom(): string
    {
        $env = getenv('TET2_EDU_MAIL_FROM');
        return ($env !== false && $env !== '') ? $env : self::DEFAULT_MAIL_FROM;
    }

    /** 受講URL(トークン型)。 */
    public static function takeUrl(string $token): string
    {
        return self::baseUrl() . '/take.php?token=' . rawurlencode($token);
    }

    /**
     * 催促メールを1通送る。成功で true。
     * localhost:25 に平文で投函(社内MTA前提)。宛先/件名/本文は呼び出し側で確定済み。
     */
    public static function send(string $toEmail, string $subject, string $body): bool
    {
        $from = self::mailFrom();
        // ヘッダインジェクション防止: 宛先・件名から CR/LF を除去
        $toEmail = str_replace(["\r", "\n"], '', $toEmail);
        $subject = str_replace(["\r", "\n"], '', $subject);
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (self::$transport !== null) {
            return (bool) (self::$transport)($toEmail, $subject, $body);
        }

        // ブラウザの E2E 用の出口。SMTP へ投函せず、1通を1つの JSON ファイルに書く(本番では設定しない)。
        // E2E はここから招待メールの URL を取り出す。
        $outbox = getenv('TET2_MAIL_OUTBOX_DIR');
        if (is_string($outbox) && $outbox !== '') {
            if (!is_dir($outbox) || !is_writable($outbox)) {
                return false;
            }
            $file = rtrim($outbox, '/') . '/' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.json';
            $json = json_encode(['to' => $toEmail, 'from' => $from, 'subject' => $subject, 'body' => $body], JSON_UNESCAPED_UNICODE);
            return $json !== false && file_put_contents($file, $json, LOCK_EX) !== false;
        }

        // テストから localhost:25 へ実送信しないための逃がし口。
        // 宛先の検証までは通したうえで、投函だけを省いて成功扱いにする。
        if (getenv('TET2_EDU_MAIL_DISABLE') === '1') {
            return true;
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'From: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        $conn = @fsockopen('localhost', 25, $errno, $errstr, 10);
        if ($conn === false) {
            return false;
        }
        $ok = true;
        try {
            self::expect($conn, '220');
            self::cmd($conn, 'HELO localhost', '250');
            self::cmd($conn, 'MAIL FROM:<' . $from . '>', '250');
            self::cmd($conn, 'RCPT TO:<' . $toEmail . '>', '250');
            self::cmd($conn, 'DATA', '354');
            $data = 'To: ' . $toEmail . "\r\n"
                . 'Subject: ' . $encodedSubject . "\r\n"
                . implode("\r\n", $headers) . "\r\n"
                . "\r\n"
                . self::dotStuff($body) . "\r\n"
                . '.';
            self::cmd($conn, $data, '250');
            self::cmd($conn, 'QUIT', '221');
        } catch (\Throwable $e) {
            $ok = false;
        } finally {
            @fclose($conn);
        }
        return $ok;
    }

    /** SMTP コマンド送信 + 応答コード検証。 */
    private static function cmd($conn, string $line, string $expectCode): void
    {
        fwrite($conn, $line . "\r\n");
        self::expect($conn, $expectCode);
    }

    private static function expect($conn, string $code): void
    {
        $resp = '';
        while (($line = fgets($conn, 515)) !== false) {
            $resp .= $line;
            // マルチライン応答は 4文字目が '-'。最終行は空白。
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        if (strncmp($resp, $code, strlen($code)) !== 0) {
            throw new RuntimeException('SMTP 応答不正: expected ' . $code . ', got ' . trim($resp));
        }
    }

    /** 行頭のドットを二重化(SMTP のドット詰め)。 */
    private static function dotStuff(string $body): string
    {
        $body = str_replace("\r\n", "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        return preg_replace('/^\./m', '..', $body);
    }
}
