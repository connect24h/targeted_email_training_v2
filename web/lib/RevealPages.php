<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * テナントごとの種明かしページ(G29)。名前つきの HTML を複数持ち、キャンペーンごとに選べる。
 *
 * - HTML の本体はテナントの data_dir/reveal-pages/reveal-<id>.html に置き、DB にはメタ情報だけ持つ。
 * - 既存の単一の reveal.html は「既定」として残す。参照のないキャンペーンは既定を使う(配信経路のフォールバック)。
 * - 検証は既存の種明かし upload と同じ(master_upload の mu_validate_html はここへ委譲する)。
 * - テナントをまたいだ参照はしない(すべて tenant_id で絞る)。
 */
final class RevealPages
{
    public const MAX_PAGES = 20;
    private const SUBDIR = 'reveal-pages';

    /**
     * アップロードされた HTML の妥当性(master_upload の mu_validate_html と同じ規則)。
     * @throws DomainException 不正な場合(コード 400)
     */
    public static function validate(string $html): void
    {
        if ($html === '') {
            throw new DomainException('内容が空です', 400);
        }
        if (strlen($html) > 1024 * 1024) {
            throw new DomainException('ファイルが大きすぎます（1MB以内）', 400);
        }
        $lower = strtolower($html);
        if (strpos($lower, '<html') === false && strpos($lower, '<!doctype') === false) {
            throw new DomainException('HTML ではないようです（<html> か <!DOCTYPE> が必要）', 400);
        }
        if (preg_match('/<\s*script\b/i', $html)) {
            throw new DomainException('script タグは使用できません', 400);
        }
        if (preg_match('/\son\w+\s*=/i', $html)) {
            throw new DomainException('イベントハンドラ属性（onclick 等）は使用できません', 400);
        }
        if (preg_match('/<\s*iframe\b/i', $html)) {
            throw new DomainException('iframe タグは使用できません', 400);
        }
        if (preg_match('/<\s*object\b/i', $html)) {
            throw new DomainException('object タグは使用できません', 400);
        }
        if (preg_match('/<\s*embed\b/i', $html)) {
            throw new DomainException('embed タグは使用できません', 400);
        }
        if (preg_match('/<\s*meta\b[^>]*http-equiv\s*=\s*["\']?\s*refresh/i', $html)) {
            throw new DomainException('meta refresh（自動リダイレクト）は使用できません', 400);
        }
    }

    /**
     * 種明かしページだけに当てる追加の規則。種明かしは訓練の後に受講者へ見せるページなので、
     * 入力フォーム、リンクの基準の書き換え、javascript: と data: の URL を使わせない。
     * 偽のログイン画面(認証マスタ)は入力フォームが要るので、共通の validate には入れない。
     */
    public static function validateReveal(string $html): void
    {
        self::validate($html);
        if (preg_match('/<\s*base\b/i', $html)) {
            throw new DomainException('base タグは使用できません', 400);
        }
        if (preg_match('/<\s*form\b/i', $html)) {
            throw new DomainException('種明かしページに入力フォーム（form タグ）は使用できません', 400);
        }
        if (preg_match('/\b(?:href|src|action|formaction)\s*=\s*["\']?\s*(?:javascript|data|vbscript)\s*:/i', $html)) {
            throw new DomainException('javascript: や data: の URL は使用できません', 400);
        }
    }

    /** @return list<array<string,mixed>> 自テナントのページ(名前順) */
    public static function all(int $tenantId): array
    {
        return Db::all(
            'SELECT id, name, storage_name, created_by, created_at, updated_at
             FROM reveal_pages WHERE tenant_id=? ORDER BY name, id',
            [$tenantId]
        );
    }

    public static function find(int $id, int $tenantId): ?array
    {
        return Db::one('SELECT * FROM reveal_pages WHERE id=? AND tenant_id=?', [$id, $tenantId]);
    }

    /** 本文(ファイル)を読む。ファイルが無ければ null。 */
    public static function content(int $id, int $tenantId): ?string
    {
        $row = self::find($id, $tenantId);
        if ($row === null) {
            return null;
        }
        $path = self::baseDir($tenantId, false) . '/' . (string) $row['storage_name'];
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $html = @file_get_contents($path);
        return $html === false ? null : $html;
    }

    /**
     * 新規作成(=id 未指定)か更新。名前と本文を保存し、id を返す。
     * @throws DomainException 検証エラー・上限超過・対象なし
     */
    public static function save(int $tenantId, ?int $id, string $name, string $html, string $actor): int
    {
        self::validateReveal($html);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainException('ページ名を入力してください（100文字以内）', 400);
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $name) === 1) {
            throw new DomainException('ページ名に使用できない文字が含まれています', 400);
        }
        $dir = self::baseDir($tenantId, true);
        if ($id !== null) {
            $row = self::find($id, $tenantId);
            if ($row === null) {
                throw new DomainException('種明かしページが見つかりません', 404);
            }
            Db::run("UPDATE reveal_pages SET name=?, updated_at=datetime('now','localtime') WHERE id=? AND tenant_id=?",
                [$name, $id, $tenantId]);
            self::writeFile($dir . '/' . (string) $row['storage_name'], $html);
            return $id;
        }
        $count = (int) (Db::one('SELECT COUNT(*) n FROM reveal_pages WHERE tenant_id=?', [$tenantId])['n'] ?? 0);
        if ($count >= self::MAX_PAGES) {
            throw new DomainException('種明かしページは最大' . self::MAX_PAGES . '件までです', 400);
        }
        $newId = Db::insert('INSERT INTO reveal_pages (tenant_id, name, storage_name, created_by) VALUES (?,?,?,?)',
            [$tenantId, $name, 'pending', $actor]);
        $storage = 'reveal-' . $newId . '.html';
        Db::run('UPDATE reveal_pages SET storage_name=? WHERE id=?', [$storage, $newId]);
        self::writeFile($dir . '/' . $storage, $html);
        return $newId;
    }

    /**
     * 削除する。このページを選んでいるキャンペーンは既定(reveal.html)へ戻す(参照を NULL にする)。
     * @throws DomainException 対象なし
     */
    public static function delete(int $id, int $tenantId): void
    {
        $row = self::find($id, $tenantId);
        if ($row === null) {
            throw new DomainException('種明かしページが見つかりません', 404);
        }
        Db::run('UPDATE campaigns SET reveal_page_id=NULL WHERE reveal_page_id=? AND tenant_id=?', [$id, $tenantId]);
        Db::run('DELETE FROM reveal_pages WHERE id=? AND tenant_id=?', [$id, $tenantId]);
        $path = self::baseDir($tenantId, false) . '/' . (string) $row['storage_name'];
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** テナントの data_dir/reveal-pages。$create=true なら作る。 */
    private static function baseDir(int $tenantId, bool $create): string
    {
        $t = Db::one('SELECT data_dir FROM tenants WHERE id=?', [$tenantId]);
        $dataDir = rtrim((string) ($t['data_dir'] ?? ''), '/');
        if ($dataDir === '') {
            throw new DomainException('テナントのデータ領域がありません', 404);
        }
        $dir = $dataDir . '/' . self::SUBDIR;
        if ($create && !is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('種明かしページの保存先を作成できません');
        }
        return $dir;
    }

    private static function writeFile(string $path, string $html): void
    {
        if (@file_put_contents($path, $html, LOCK_EX) === false) {
            throw new RuntimeException('種明かしページを保存できません');
        }
        @chmod($path, 0640);
    }
}
