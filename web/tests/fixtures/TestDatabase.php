<?php
declare(strict_types=1);

final class TestDatabase
{
    public static function create(string $path, bool $seed = true): void
    {
        if ($path === '' || file_exists($path)) {
            throw new InvalidArgumentException('新規DBの明示pathを指定してください');
        }
        $parent = dirname($path);
        if (!is_dir($parent) || !is_writable($parent)) {
            throw new InvalidArgumentException('DB作成先directoryへ書き込めません');
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-edu.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-automation.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-position.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-risk.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-report-mail.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-suspicious-mail.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-template-snapshots.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-credential-captures.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-survey.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-edu-media.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-edu-delivery.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-user-password.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-learner.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-admin-mfa.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-suspicious-mail-rules.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-measurement.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-ops-b2a.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-reveal-pages.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-edu-material-versions.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-notification-templates.sql'));
        $pdo->exec(self::readFile(__DIR__ . '/../../db/schema-sending-da.sql'));
        if ($seed) {
            $pdo->exec(self::readFile(__DIR__ . '/synthetic.sql'));
        }
    }

    private static function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("fixture読込失敗: {$path}");
        }
        return $contents;
    }
}
