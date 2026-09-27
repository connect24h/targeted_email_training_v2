<?php declare(strict_types=1);

/**
 * 教育コンテンツの画像の保存と変換(08 設計書の G16、G17)。
 *
 * - 教材の PDF を、ページごとの JPEG 画像とページの文字に変換する(pdftoppm、pdftotext)。
 * - PHP の受信の上限(post_max_size 8MB)を超える PDF のため、分割アップロードを受け付ける。
 * - 設問の画像(PNG、JPEG)を保存する。
 *
 * 画像の実体は Web から直接読めない場所(既定は /opt/training/tet2-data/edu-media)に置く。
 * ファイル名とディレクトリはサーバーが決め、利用者の入力をパスに使わない。
 * 本番の PHP は proc_open を禁止しているので、外部コマンドは exec と escapeshellarg で呼ぶ。
 */
final class EduMedia
{
    public const MAX_PDF_BYTES = 60_000_000;
    public const MAX_PDF_PAGES = 200;
    public const MAX_CHUNK_BYTES = 4_200_000;
    public const MAX_IMAGE_BYTES = 5_000_000;
    private const PAGE_DPI = 180;

    public static function root(): string
    {
        $env = getenv('TET2_EDU_MEDIA_DIR');
        return is_string($env) && $env !== '' ? rtrim($env, '/') : '/opt/training/tet2-data/edu-media';
    }

    /** 教材のページ画像の置き場。共有の教材(tenant_id が NULL)は shared に置く。 */
    public static function materialDir(?int $tenantId, int $materialId): string
    {
        return self::root() . '/' . self::tenantPart($tenantId) . '/materials/' . $materialId;
    }

    public static function questionDir(?int $tenantId): string
    {
        return self::root() . '/' . self::tenantPart($tenantId) . '/questions';
    }

    /** サーバーが付けた名前だけを通す(page-001.jpg、q-12-0a1b2c3d.png)。 */
    public static function safeName(string $name): bool
    {
        return (bool) preg_match('/^(page-\d{3}\.jpg|q-\d+-[0-9a-f]{8}\.(png|jpg))$/', $name);
    }

    // ------------------------------------------------------------ 分割アップロード

    public static function beginUpload(int $tenantId, int $userId): string
    {
        self::collectGarbage();
        $id = bin2hex(random_bytes(16));
        $dir = self::uploadDir($id);
        self::mkdir($dir);
        file_put_contents($dir . '/meta.json', json_encode(['tenant_id' => $tenantId, 'user_id' => $userId, 'next' => 0]));
        file_put_contents($dir . '/data.bin', '');
        return $id;
    }

    /** 分割の1つを順番どおりに追記し、ここまでの合計バイト数を返す。 */
    public static function appendChunk(string $uploadId, int $tenantId, int $userId, int $index, string $bytes): int
    {
        $dir = self::ownedUploadDir($uploadId, $tenantId, $userId);
        $meta = json_decode((string) file_get_contents($dir . '/meta.json'), true);
        if ($index !== (int) ($meta['next'] ?? -1)) {
            throw new RuntimeException('分割の順番が正しくありません');
        }
        if (strlen($bytes) === 0 || strlen($bytes) > self::MAX_CHUNK_BYTES) {
            throw new RuntimeException('分割の大きさが不正です');
        }
        $size = filesize($dir . '/data.bin') + strlen($bytes);
        if ($size > self::MAX_PDF_BYTES) {
            throw new RuntimeException('PDF は60MB以内にしてください');
        }
        file_put_contents($dir . '/data.bin', $bytes, FILE_APPEND | LOCK_EX);
        $meta['next'] = $index + 1;
        file_put_contents($dir . '/meta.json', json_encode($meta));
        return $size;
    }

    public static function uploadPath(string $uploadId, int $tenantId, int $userId): string
    {
        return self::ownedUploadDir($uploadId, $tenantId, $userId) . '/data.bin';
    }

    public static function discardUpload(string $uploadId, int $tenantId, int $userId): void
    {
        self::removeDir(self::ownedUploadDir($uploadId, $tenantId, $userId));
    }

    // ------------------------------------------------------------ PDF の変換

    /**
     * PDF をページ画像とページの文字にし、$outDir に保存する。失敗したら $outDir に何も残さない。
     *
     * @return list<array{page_no:int,image_name:string,page_text:string,width:int,height:int}>
     */
    public static function convertPdf(string $pdfPath, string $outDir): array
    {
        $head = (string) file_get_contents($pdfPath, false, null, 0, 5);
        if ($head !== '%PDF-') {
            throw new RuntimeException('PDF ファイルではありません');
        }
        if (filesize($pdfPath) > self::MAX_PDF_BYTES) {
            throw new RuntimeException('PDF は60MB以内にしてください');
        }
        $pageCount = self::pdfPageCount($pdfPath);
        if ($pageCount < 1 || $pageCount > self::MAX_PDF_PAGES) {
            throw new RuntimeException('PDF は1〜' . self::MAX_PDF_PAGES . 'ページにしてください');
        }
        $work = $outDir . '.work-' . bin2hex(random_bytes(4));
        self::mkdir($work);
        try {
            self::run('pdftoppm -r ' . self::PAGE_DPI . ' -jpeg -jpegopt quality=85 '
                . escapeshellarg($pdfPath) . ' ' . escapeshellarg($work . '/p'));
            $texts = self::pdfTexts($pdfPath);
            $files = glob($work . '/p-*.jpg') ?: [];
            natsort($files);
            $files = array_values($files);
            if (count($files) !== $pageCount) {
                throw new RuntimeException('ページ画像の数が PDF のページ数と一致しません');
            }
            $pages = [];
            foreach ($files as $i => $file) {
                $no = $i + 1;
                $name = sprintf('page-%03d.jpg', $no);
                rename($file, $work . '/' . $name);
                $size = getimagesize($work . '/' . $name) ?: [0, 0];
                $pages[] = [
                    'page_no' => $no,
                    'image_name' => $name,
                    'page_text' => mb_substr(trim($texts[$i] ?? ''), 0, 20000),
                    'width' => (int) $size[0],
                    'height' => (int) $size[1],
                ];
            }
            if (is_dir($outDir)) {
                self::removeDir($outDir);
            }
            self::mkdir(dirname($outDir));
            if (!rename($work, $outDir)) {
                throw new RuntimeException('ページ画像を保存できません');
            }
            return $pages;
        } catch (Throwable $error) {
            self::removeDir($work);
            throw $error instanceof RuntimeException ? $error : new RuntimeException('PDF を変換できません');
        }
    }

    // ------------------------------------------------------------ 設問の画像

    public static function saveQuestionImage(?int $tenantId, int $questionId, string $bytes): string
    {
        if (strlen($bytes) === 0 || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new RuntimeException('画像は5MB以内にしてください');
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            $ext = 'png';
        } elseif (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            $ext = 'jpg';
        } else {
            throw new RuntimeException('画像は PNG か JPEG にしてください');
        }
        if (getimagesizefromstring($bytes) === false) {
            throw new RuntimeException('画像を読み取れません');
        }
        $dir = self::questionDir($tenantId);
        self::mkdir($dir);
        $name = 'q-' . $questionId . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        file_put_contents($dir . '/' . $name, $bytes, LOCK_EX);
        return $name;
    }

    public static function removeQuestionImage(?int $tenantId, ?string $name): void
    {
        if ($name !== null && self::safeName($name)) {
            @unlink(self::questionDir($tenantId) . '/' . $name);
        }
    }

    /** 画像を返すときの Content-Type。 */
    public static function mimeOf(string $name): string
    {
        return str_ends_with($name, '.png') ? 'image/png' : 'image/jpeg';
    }

    // ------------------------------------------------------------ 内部

    private static function tenantPart(?int $tenantId): string
    {
        return $tenantId === null ? 'shared' : 't' . $tenantId;
    }

    private static function uploadDir(string $uploadId): string
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $uploadId)) {
            throw new RuntimeException('アップロードの ID が不正です');
        }
        return self::root() . '/tmp/' . $uploadId;
    }

    private static function ownedUploadDir(string $uploadId, int $tenantId, int $userId): string
    {
        $dir = self::uploadDir($uploadId);
        $meta = is_file($dir . '/meta.json') ? json_decode((string) file_get_contents($dir . '/meta.json'), true) : null;
        if (!is_array($meta) || (int) $meta['tenant_id'] !== $tenantId || (int) $meta['user_id'] !== $userId) {
            throw new RuntimeException('アップロードが見つかりません');
        }
        return $dir;
    }

    private static function pdfPageCount(string $pdfPath): int
    {
        $out = self::run('pdfinfo ' . escapeshellarg($pdfPath));
        return preg_match('/^Pages:\s+(\d+)/m', $out, $m) ? (int) $m[1] : 0;
    }

    /** @return list<string> ページごとの文字(pdftotext はページを改ページ文字で区切る) */
    private static function pdfTexts(string $pdfPath): array
    {
        $out = self::run('pdftotext -layout -enc UTF-8 ' . escapeshellarg($pdfPath) . ' -');
        return explode("\f", $out);
    }

    /** 直近の外部コマンドの失敗の詳細(サーバーのログ用。利用者には見せない)。 */
    public static string $lastError = '';

    private static function run(string $command): string
    {
        $output = [];
        $code = 0;
        $errFile = tempnam(sys_get_temp_dir(), 'tet2-edu-media-err-');
        exec($command . ' 2>' . escapeshellarg((string) $errFile), $output, $code);
        $stderr = is_string($errFile) ? trim((string) @file_get_contents($errFile)) : '';
        if (is_string($errFile)) {
            @unlink($errFile);
        }
        if ($code !== 0) {
            // pdftoppm がない、権限がない、PDF が壊れている、を後からログで見分けられるようにする
            self::$lastError = "exit={$code} cmd=" . strtok($command, ' ') . ' stderr=' . mb_substr($stderr, 0, 500);
            throw new RuntimeException('PDF を変換できません');
        }
        return implode("\n", $output);
    }

    /**
     * 放置された一時ファイルを消す。アップロードの一時ディレクトリは24時間、変換途中の作業ディレクトリは1時間で消す。
     * ブラウザを閉じるなどで取込まで進まなかったアップロードや、途中で止まった変換の残りを溜めないため。
     *
     * @return int 消したディレクトリの数
     */
    public static function collectGarbage(int $now = 0): int
    {
        $now = $now > 0 ? $now : time();
        $removed = 0;
        foreach (glob(self::root() . '/tmp/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (preg_match('/\/[0-9a-f]{32}$/', $dir) && filemtime($dir) < $now - 86400) {
                self::removeDir($dir);
                $removed++;
            }
        }
        foreach (glob(self::root() . '/*/materials/*.work-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < $now - 3600) {
                self::removeDir($dir);
                $removed++;
            }
        }
        return $removed;
    }

    private static function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('保存先を作成できません');
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir) || !str_starts_with($dir, self::root() . '/')) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
