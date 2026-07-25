<?php
/**
 * 教育受講メール送信(受講依頼・催促)。
 * ローカル Postfix(localhost:25) に直接投函する。フィッシング訓練の send_email.py とは別系統
 * (あちらは data-dir/koban 駆動の一括送信。こちらは個別トークンURLの案内メール)。
 *
 * 受講URLのベースは環境変数 TET2_EDU_BASE_URL で上書き可。既定は受講者ポータル sat.cojp.online。
 * 差出人は TET2_EDU_MAIL_FROM で上書き可。既定は no-reply@cojp.online。
 */
declare(strict_types=1);

final class EduMailer
{
    private const DEFAULT_BASE_URL  = 'https://sat.cojp.online';
    private const DEFAULT_MAIL_FROM = 'no-reply@cojp.online';

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
