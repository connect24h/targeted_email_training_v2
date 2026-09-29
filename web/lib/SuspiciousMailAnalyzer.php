<?php
declare(strict_types=1);

/**
 * 不審メールの所見（findings）と推奨分類を決める。I/O を持たない純関数。
 *
 * 入力は ReportMailParser::parse($raw, ['collect' => true]) の result['analysis'] と、
 * 呼び出し側が用意する文脈（自社ドメイン、訓練メール判定）・外部評判（reputation_cache の行）。
 * 判定対象は最深の message/rfc822（転送添付の内側）。無ければ外側メッセージ。
 */
final class SuspiciousMailAnalyzer
{
    /** ルールや閾値を変えたら上げる。suspicious_mails.analyzer_version と比較して再解析の要否を判断する。 */
    public const VERSION = 1;
    public const THREAT_THRESHOLD = 25;
    public const SPAM_THRESHOLD = 10;

    public const CATEGORIES = ['training', 'safe', 'spam', 'threat', 'undetermined'];

    private const SHORTENERS = ['bit.ly', 't.co', 'tinyurl.com', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly', 'cutt.ly',
        'rebrand.ly', 'x.gd', 'urlz.fr', 'shorturl.at', 'tiny.cc', 'lnkd.in', 'rb.gy', 'onl.la'];
    private const DANGEROUS_EXTENSIONS = ['exe', 'js', 'jse', 'vbs', 'vbe', 'wsf', 'wsh', 'scr', 'hta', 'iso', 'img', 'lnk',
        'bat', 'cmd', 'ps1', 'msi', 'jar', 'com', 'pif', 'docm', 'xlsm', 'pptm', 'dll', 'cpl', 'reg', 'vhd', 'vhdx'];
    private const OFFICE_MACRO_EXTENSIONS = ['docm', 'xlsm', 'pptm'];
    private const KEYWORDS = [
        'body_urgency' => ['至急', '本日中', '24時間以内', '48時間以内', 'アカウントが停止', 'アカウントは停止', '利用停止', '緊急',
            'urgent', 'immediately', 'within 24 hours', 'account will be suspended', 'final notice'],
        'body_credential' => ['パスワード', 'ログインして', '本人確認', 'アカウント確認', '認証情報', 'verify your account', 'sign in',
            'log in to', 'confirm your identity', 'password'],
        'body_payment' => ['請求', '振込', '支払い', 'お支払', '送金', 'invoice', 'payment', 'wire transfer', 'bank details'],
    ];
    private const SEVERITY_ORDER = ['high' => 3, 'medium' => 2, 'low' => 1, 'info' => 0];

    /**
     * @param array $analysis parser の result['analysis']
     * @param array $context  ['own_domains' => string[], 'training_tracking_ids' => string[], 'parse_error' => ?string,
     *                          'tenant_rules' => list<array{id:int,name:string,kind:string,value:string}>(テナントが登録した条件)]
     * @param array $reputation ['file' => [sha256 => row], 'url' => [url => row]]（reputation_cache の行）
     * @return array{target_index:?int, findings:array, score:int, suggested_category:string, summary:array}
     */
    public static function analyze(array $analysis, array $context = [], array $reputation = []): array
    {
        $messages = $analysis['messages'] ?? [];
        if ($messages === []) {
            return ['target_index' => null, 'findings' => [], 'score' => 0, 'suggested_category' => 'undetermined',
                'summary' => ['high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0, 'reason' => $context['parse_error'] ?? 'no message']];
        }
        $targetIndex = self::targetIndex($messages);
        $target = $messages[$targetIndex];
        $findings = [];
        $ownDomains = array_map('strtolower', $context['own_domains'] ?? []);

        $trainingIds = array_values(array_unique($context['training_tracking_ids'] ?? []));
        if ($trainingIds !== []) {
            $findings[] = self::finding('training_mail', 'info', 0, '訓練メールです（追跡 ID が一致）', ['tracking_ids' => $trainingIds]);
            return self::result($targetIndex, $findings, 'training');
        }

        self::authRules($target, $findings);
        self::senderRules($target, $ownDomains, $findings);
        self::routeRules($target, $findings);
        self::urlRules($target, $reputation['url'] ?? [], $findings);
        self::attachmentRules($target, $reputation['file'] ?? [], $findings);
        self::bodyRules($target, $findings);
        self::tenantRules($target, $context['tenant_rules'] ?? [], $findings);

        usort($findings, static fn(array $a, array $b): int =>
            (self::SEVERITY_ORDER[$b['severity']] <=> self::SEVERITY_ORDER[$a['severity']]) ?: ($b['score'] <=> $a['score']));
        return self::result($targetIndex, $findings, null);
    }

    public static function categoryFor(int $score, array $findings): string
    {
        if ($score >= self::THREAT_THRESHOLD) {
            return 'threat';
        }
        if ($score >= self::SPAM_THRESHOLD) {
            return 'spam';
        }
        // 10 点未満は単発の軽微な所見（配信業者の Return-Path 違いなど）なので安全側に倒す。
        return 'safe';
    }

    private static function result(int $targetIndex, array $findings, ?string $forced): array
    {
        $score = array_sum(array_column($findings, 'score'));
        $summary = ['high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
        foreach ($findings as $finding) {
            $summary[$finding['severity']]++;
        }
        $category = $forced ?? self::categoryFor($score, $findings);
        if ($forced === 'training') {
            $score = 0;
        }
        return ['target_index' => $targetIndex, 'findings' => $findings, 'score' => $score,
            'suggested_category' => $category, 'summary' => $summary];
    }

    private static function finding(string $code, string $severity, int $score, string $message, array $evidence = []): array
    {
        return ['code' => $code, 'severity' => $severity, 'score' => $score, 'message' => $message, 'evidence' => $evidence];
    }

    /** 最深の message/rfc822 を判定対象にする（転送報告では内側が本体）。 */
    private static function targetIndex(array $messages): int
    {
        $index = 0;
        foreach ($messages as $i => $message) {
            if ($message['depth'] >= $messages[$index]['depth']) {
                $index = $i;
            }
        }
        return $index;
    }

    /* ---------- 認証 ---------- */

    private static function authRules(array $m, array &$findings): void
    {
        $results = $m['auth_results'] ?? [];
        if ($results === []) {
            $findings[] = self::finding('auth_missing', 'info', 0, 'Authentication-Results ヘッダーがなく、SPF/DKIM/DMARC の結果を確認できません');
            return;
        }
        $joined = strtolower(implode(' ', $results));
        $fails = $weak = [];
        foreach (['spf', 'dkim', 'dmarc'] as $method) {
            if (preg_match('/\b' . $method . '=([a-z]+)/', $joined, $match)) {
                $value = $match[1];
                if ($value === 'fail' || $value === 'permerror') {
                    $fails[] = "$method=$value";
                } elseif (in_array($value, ['none', 'softfail', 'temperror', 'neutral'], true)) {
                    $weak[] = "$method=$value";
                }
            }
        }
        if ($fails !== []) {
            $findings[] = self::finding('auth_fail', 'high', 15, '送信ドメイン認証に失敗しています（' . implode(', ', $fails) . '）', ['results' => $fails]);
        }
        if ($weak !== []) {
            $findings[] = self::finding('auth_weak', 'medium', 8, '送信ドメイン認証が不十分です（' . implode(', ', $weak) . '）', ['results' => $weak]);
        }
    }

    /* ---------- 送信者 ---------- */

    private static function domainOf(?string $email): ?string
    {
        if ($email === null || !str_contains($email, '@')) {
            return null;
        }
        return strtolower(substr($email, strrpos($email, '@') + 1));
    }

    private static function senderRules(array $m, array $ownDomains, array &$findings): void
    {
        $fromDomain = self::domainOf($m['from_email'] ?? null);
        $returnDomain = self::domainOf($m['return_path'] ?? null);
        $replyDomain = self::domainOf($m['reply_to'] ?? null);
        if ($fromDomain !== null && $returnDomain !== null && $returnDomain !== $fromDomain) {
            $findings[] = self::finding('return_path_mismatch', 'medium', 8,
                'From と Return-Path のドメインが異なります', ['from' => $m['from_email'], 'return_path' => $m['return_path']]);
        }
        if ($fromDomain !== null && $replyDomain !== null && $replyDomain !== $fromDomain) {
            $findings[] = self::finding('reply_to_mismatch', 'medium', 8,
                '返信先（Reply-To）が From と別のドメインです', ['from' => $m['from_email'], 'reply_to' => $m['reply_to']]);
        }
        $name = (string) ($m['from_name'] ?? '');
        if ($name !== '' && preg_match('/[a-z0-9._%+-]+@([a-z0-9.-]+\.[a-z]{2,})/i', $name, $match)
            && strcasecmp($match[0], (string) $m['from_email']) !== 0) {
            $findings[] = self::finding('display_name_email', 'medium', 8,
                '表示名に実際の送信元と異なるメールアドレスが含まれています', ['display_name' => $name, 'from' => $m['from_email']]);
        }
        if ($name !== '' && $fromDomain !== null && $ownDomains !== [] && !in_array($fromDomain, $ownDomains, true)) {
            foreach ($ownDomains as $own) {
                if ($own !== '' && str_contains(strtolower($name), $own)) {
                    $findings[] = self::finding('display_name_lookalike', 'high', 10,
                        '表示名が自社ドメインを装っていますが、送信元は外部ドメインです', ['display_name' => $name, 'from' => $m['from_email']]);
                    break;
                }
            }
        }
    }

    /* ---------- 経路 ---------- */

    private static function routeRules(array $m, array &$findings): void
    {
        $received = $m['received'] ?? [];
        if ($received === []) {
            return;
        }
        $oldest = $received[count($received) - 1];
        if (preg_match('/\[(?:IPv6:)?([0-9a-f.:]+)\]|\(([0-9]{1,3}(?:\.[0-9]{1,3}){3})\)/i', $oldest, $match)) {
            $ip = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
            if ($ip !== '') {
                $findings[] = self::finding('first_hop_ip', 'info', 0, '最初の送信元 IP: ' . $ip, ['ip' => $ip, 'received' => $oldest]);
            }
        }
    }

    /* ---------- URL ---------- */

    private static function urlRules(array $m, array $reputation, array &$findings): void
    {
        foreach ($m['urls'] ?? [] as $entry) {
            $url = $entry['unwrapped'];
            $parts = parse_url($url);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $evidence = ['url' => $url];
            if ($entry['raw'] !== $url) {
                $findings[] = self::finding('url_unwrapped', 'info', 0, 'リンク保護サービスの包みを外しました', ['raw' => $entry['raw'], 'url' => $url]);
            }
            if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) !== false) {
                $findings[] = self::finding('url_ip_literal', 'high', 10, 'リンク先がドメイン名ではなく IP アドレスです', $evidence);
            }
            if ($host !== '' && (str_contains($host, 'xn--') || preg_match('/[^\x20-\x7e]/', $host) === 1)) {
                $findings[] = self::finding('url_punycode', 'high', 10, 'リンク先ホストに国際化ドメイン（Punycode / 非 ASCII）が含まれます', $evidence);
            }
            if (in_array($host, self::SHORTENERS, true)) {
                $findings[] = self::finding('url_shortener', 'medium', 6, '短縮 URL サービスを経由しています（' . $host . '）', $evidence);
            }
            if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
                $findings[] = self::finding('url_nonstandard_port', 'medium', 6, 'リンク先が標準以外のポートを使っています（' . $parts['port'] . '）', $evidence);
            }
            if (isset($parts['user'])) {
                $findings[] = self::finding('url_userinfo', 'high', 10, 'URL に「@」を使った偽装（ユーザー情報部）が含まれます', $evidence);
            }
            $display = $entry['display'] ?? null;
            if ($display !== null && preg_match('~^https?://([^/\s]+)~i', $display, $dm)) {
                $displayHost = strtolower($dm[1]);
                if ($displayHost !== $host) {
                    $findings[] = self::finding('url_display_mismatch', 'high', 12,
                        '表示されているリンク文字列と実際のリンク先が異なります', ['display' => $display, 'url' => $url]);
                }
            }
            $rep = $reputation[$url] ?? null;
            if ($rep !== null) {
                self::reputationFinding('vt_url', $rep, $evidence, $findings);
            }
        }
    }

    /* ---------- 添付 ---------- */

    private static function attachmentRules(array $m, array $reputation, array &$findings): void
    {
        foreach ($m['attachments'] ?? [] as $att) {
            $name = $att['filename'];
            $ext = strtolower((string) $att['extension']);
            $evidence = ['filename' => $name, 'sha256' => $att['sha256'], 'size' => $att['size']];
            if (in_array($ext, self::DANGEROUS_EXTENSIONS, true)) {
                $findings[] = self::finding('attachment_dangerous_ext', 'high', 15, '実行可能・マクロ付きの添付ファイルです（.' . $ext . '）', $evidence);
            } elseif (in_array($ext, self::OFFICE_MACRO_EXTENSIONS, true)) {
                $findings[] = self::finding('attachment_office_macro', 'info', 0, 'マクロを含む Office ファイルです', $evidence);
            }
            if (preg_match('/\.(pdf|docx?|xlsx?|pptx?|txt|jpe?g|png|gif)\.[a-z0-9]{2,4}$/i', $name)) {
                $findings[] = self::finding('attachment_double_ext', 'high', 10, '二重拡張子で文書ファイルに見せかけています', $evidence);
            }
            $dangerousEntries = [];
            foreach ($att['zip_entries'] ?? [] as $entry) {
                $entryExt = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                if (in_array($entryExt, self::DANGEROUS_EXTENSIONS, true)) {
                    $dangerousEntries[] = $entry;
                }
            }
            if ($dangerousEntries !== []) {
                $findings[] = self::finding('attachment_zip_dangerous', 'high', 15,
                    '圧縮ファイルの中に実行可能ファイルがあります（' . implode(', ', array_slice($dangerousEntries, 0, 5)) . '）',
                    $evidence + ['entries' => $dangerousEntries]);
            }
            $rep = $reputation[$att['sha256']] ?? null;
            if ($rep !== null) {
                self::reputationFinding('vt_file', $rep, $evidence, $findings);
            }
        }
    }

    private static function reputationFinding(string $prefix, array $rep, array $evidence, array &$findings): void
    {
        $stats = ['malicious' => (int) ($rep['malicious'] ?? 0), 'suspicious' => (int) ($rep['suspicious'] ?? 0),
            'harmless' => (int) ($rep['harmless'] ?? 0), 'undetected' => (int) ($rep['undetected'] ?? 0)];
        if (!(int) ($rep['found'] ?? 0)) {
            $findings[] = self::finding('vt_unknown', 'info', 0, 'VirusTotal に登録がありません', $evidence);
            return;
        }
        if ($stats['malicious'] > 0) {
            $findings[] = self::finding($prefix . '_malicious', 'high', 20,
                'VirusTotal で ' . $stats['malicious'] . ' 件のエンジンが悪性と判定しています', $evidence + $stats);
        } elseif ($stats['suspicious'] > 0) {
            $findings[] = self::finding($prefix . '_suspicious', 'medium', 8,
                'VirusTotal で ' . $stats['suspicious'] . ' 件のエンジンが疑わしいと判定しています', $evidence + $stats);
        } else {
            $findings[] = self::finding($prefix . '_clean', 'info', 0, 'VirusTotal では悪性判定がありません', $evidence + $stats);
        }
    }

    /* ---------- テナントが登録した条件 ---------- */

    /**
     * 登録した条件(送信者、件名の語、URL のドメイン)に当たれば、条件ごとに所見を1つ足す。
     * 点数は足さない(推奨分類を自動で変えない)。目立たせるため重さは「中」。値は SuspiciousMailRules が小文字にして保存している。
     */
    private static function tenantRules(array $m, array $rules, array &$findings): void
    {
        $from = strtolower((string) ($m['from_email'] ?? ''));
        $fromDomain = self::domainOf($from !== '' ? $from : null);
        $subject = mb_strtolower((string) ($m['subject'] ?? ''), 'UTF-8');
        $hosts = [];
        foreach ($m['urls'] ?? [] as $entry) {
            $host = strtolower((string) (parse_url((string) ($entry['unwrapped'] ?? ''), PHP_URL_HOST) ?? ''));
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }
        foreach ($rules as $rule) {
            $value = (string) ($rule['value'] ?? '');
            if ($value === '') {
                continue;
            }
            $matched = match ($rule['kind'] ?? '') {
                'sender' => str_contains($value, '@') ? ($from === $value ? $from : null)
                    : ($fromDomain !== null && self::domainMatches($fromDomain, $value) ? $from : null),
                'subject_keyword' => str_contains($subject, mb_strtolower($value, 'UTF-8')) ? (string) ($m['subject'] ?? '') : null,
                'url_domain' => self::firstHostMatching(array_keys($hosts), $value),
                default => null,
            };
            if ($matched !== null) {
                $findings[] = self::finding('tenant_rule', 'medium', 0, '登録した条件に一致: ' . (string) ($rule['name'] ?? ''),
                    ['rule_id' => (int) ($rule['id'] ?? 0), 'rule_name' => (string) ($rule['name'] ?? ''), 'kind' => (string) $rule['kind'],
                     'value' => $value, 'matched' => $matched]);
            }
        }
    }

    /** $host が $domain そのものか、そのサブドメインか。 */
    private static function domainMatches(string $host, string $domain): bool
    {
        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    private static function firstHostMatching(array $hosts, string $domain): ?string
    {
        foreach ($hosts as $host) {
            if (self::domainMatches((string) $host, $domain)) {
                return (string) $host;
            }
        }
        return null;
    }

    /* ---------- 本文 ---------- */

    private static function bodyRules(array $m, array &$findings): void
    {
        $text = mb_strtolower(($m['subject'] ?? '') . "\n" . ($m['text'] ?? '') . "\n" . ($m['html_text'] ?? ''), 'UTF-8');
        $severity = ['body_urgency' => ['low', 3], 'body_credential' => ['medium', 5], 'body_payment' => ['low', 3]];
        $label = ['body_urgency' => '緊急性をあおる表現', 'body_credential' => '認証情報やログインを求める表現', 'body_payment' => '支払い・送金に関する表現'];
        foreach (self::KEYWORDS as $code => $words) {
            $hits = [];
            foreach ($words as $word) {
                if (str_contains($text, mb_strtolower($word, 'UTF-8'))) {
                    $hits[] = $word;
                }
            }
            if ($hits !== []) {
                [$sev, $score] = $severity[$code];
                $findings[] = self::finding($code, $sev, $score, $label[$code] . 'があります（' . implode('、', array_slice($hits, 0, 5)) . '）', ['keywords' => $hits]);
            }
        }
    }
}
