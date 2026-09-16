<?php
declare(strict_types=1);

/**
 * 外部依存なしで、教材取込に必要な範囲だけ Office Open XML を読む。
 * マクロ・画像・外部リンク・数式は評価せず、ZIP 内の XML 文字列だけを抽出する。
 */
final class OfficeDocumentReader
{
    private const MAX_ARCHIVE_BYTES = 5_242_880;
    private const MAX_UNCOMPRESSED_BYTES = 26_214_400;
    private const MAX_ENTRIES = 2_000;

    /** @return list<array{title:string,body:string}> */
    public static function readPowerPoint(string $bytes): array
    {
        return self::withArchive($bytes, function (ZipArchive $zip): array {
            $names = self::powerPointSlideNames($zip);
            if ($names === [] || count($names) > 50) {
                throw new RuntimeException('PowerPointのスライドは1〜50枚にしてください');
            }

            $slides = [];
            foreach (array_values($names) as $index => $name) {
                $xml = self::entry($zip, $name);
                $slides[] = self::powerPointSlide($xml, $index + 1);
            }
            return $slides;
        });
    }

    /** @return list<list<string>> */
    public static function readFirstWorksheet(string $bytes): array
    {
        return self::withArchive($bytes, function (ZipArchive $zip): array {
            $sheetNames = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (preg_match('#^xl/worksheets/sheet([1-9][0-9]*)\.xml$#', $name, $match)) {
                    $sheetNames[(int) $match[1]] = $name;
                }
            }
            ksort($sheetNames, SORT_NUMERIC);
            $sheetName = reset($sheetNames);
            if (!is_string($sheetName)) {
                throw new RuntimeException('Excelにワークシートがありません');
            }
            $shared = self::sharedStrings($zip);
            return self::worksheetRows(self::entry($zip, $sheetName), $shared);
        });
    }

    private static function withArchive(string $bytes, callable $callback): mixed
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_ARCHIVE_BYTES || !str_starts_with($bytes, 'PK')) {
            throw new RuntimeException('Officeファイルが不正か、5MBを超えています');
        }
        $path = tempnam(sys_get_temp_dir(), 'tet2-office-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new RuntimeException('一時ファイルを作成できません');
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($path) !== true) {
                throw new RuntimeException('Officeファイルを開けません');
            }
            $opened = true;
            self::assertArchiveLimits($zip);
            return $callback($zip);
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }

    private static function assertArchiveLimits(ZipArchive $zip): void
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            throw new RuntimeException('Officeファイル内の項目数が上限を超えています');
        }
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/')
                || preg_match('#(^|/)\.\.(/|$)#', $name)) {
                throw new RuntimeException('Officeファイル内のパスが不正です');
            }
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('Officeファイルの展開サイズが上限を超えています');
            }
        }
    }

    private static function entry(ZipArchive $zip, string $name): string
    {
        $content = $zip->getFromName($name);
        if (!is_string($content)) {
            throw new RuntimeException('Officeファイル内のXMLを読み取れません');
        }
        return $content;
    }

    /** @return list<string> */
    private static function powerPointSlideNames(ZipArchive $zip): array
    {
        $numbered = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (preg_match('#^ppt/slides/slide([1-9][0-9]*)\.xml$#', $name, $match)) {
                $numbered[(int) $match[1]] = $name;
            }
        }
        ksort($numbered, SORT_NUMERIC);

        $presentation = $zip->getFromName('ppt/presentation.xml');
        $relationships = $zip->getFromName('ppt/_rels/presentation.xml.rels');
        if (!is_string($presentation) || !is_string($relationships)) {
            return array_values($numbered);
        }
        preg_match_all('#<Relationship\b([^>]*)/?>#s', $relationships, $relationshipMatches);
        $targetById = [];
        foreach ($relationshipMatches[1] ?? [] as $attributes) {
            if (preg_match('#\bId=["\']([^"\']+)["\']#', $attributes, $id)
                && preg_match('#\bTarget=["\']slides/(slide[1-9][0-9]*\.xml)["\']#', $attributes, $target)) {
                $targetById[$id[1]] = 'ppt/slides/' . $target[1];
            }
        }
        preg_match_all('#<p:sldId\b[^>]*\br:id=["\']([^"\']+)["\'][^>]*/?>#', $presentation, $slideMatches);
        $ordered = [];
        foreach ($slideMatches[1] ?? [] as $relationshipId) {
            $name = $targetById[$relationshipId] ?? null;
            if (is_string($name) && in_array($name, $numbered, true)) {
                $ordered[] = $name;
            }
        }
        foreach ($numbered as $name) {
            if (!in_array($name, $ordered, true)) {
                $ordered[] = $name;
            }
        }
        return $ordered;
    }

    /** @return array{title:string,body:string} */
    private static function powerPointSlide(string $xml, int $number): array
    {
        preg_match_all('#<p:sp\b[^>]*>(.*?)</p:sp>#s', $xml, $shapeMatches);
        $title = '';
        $bodyParagraphs = [];
        foreach ($shapeMatches[1] ?? [] as $shape) {
            $paragraphs = self::paragraphTexts($shape);
            if ($paragraphs === []) {
                continue;
            }
            $isTitle = preg_match('#<p:ph\b[^>]*\btype=["\'](?:title|ctrTitle)["\']#', $shape) === 1;
            if ($isTitle && $title === '') {
                $title = implode("\n", $paragraphs);
            } else {
                array_push($bodyParagraphs, ...$paragraphs);
            }
        }
        if ($title === '' && $bodyParagraphs !== []) {
            $title = (string) array_shift($bodyParagraphs);
        }
        if ($title === '') {
            $title = 'スライド ' . $number;
        }
        $body = trim(implode("\n", $bodyParagraphs));
        if ($body === '') {
            $body = $title;
        }
        if (mb_strlen($title) > 200 || mb_strlen($body) > 10_000) {
            throw new RuntimeException('スライドの文字数が上限を超えています');
        }
        return ['title' => $title, 'body' => $body];
    }

    /** @return list<string> */
    private static function paragraphTexts(string $xml): array
    {
        preg_match_all('#<a:p\b[^>]*>(.*?)</a:p>#s', $xml, $paragraphMatches);
        $paragraphs = [];
        foreach ($paragraphMatches[1] ?? [] as $paragraph) {
            preg_match_all('#<a:t\b[^>]*>(.*?)</a:t>#s', $paragraph, $textMatches);
            $text = trim(implode('', array_map([self::class, 'decodeXml'], $textMatches[1] ?? [])));
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }
        return $paragraphs;
    }

    /** @return list<string> */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($xml)) {
            return [];
        }
        preg_match_all('#<si\b[^>]*>(.*?)</si>#s', $xml, $itemMatches);
        $strings = [];
        foreach ($itemMatches[1] ?? [] as $item) {
            preg_match_all('#<t\b[^>]*>(.*?)</t>#s', $item, $textMatches);
            $strings[] = implode('', array_map([self::class, 'decodeXml'], $textMatches[1] ?? []));
        }
        return $strings;
    }

    /** @param list<string> $shared @return list<list<string>> */
    private static function worksheetRows(string $xml, array $shared): array
    {
        preg_match_all('#<row\b[^>]*>(.*?)</row>#s', $xml, $rowMatches);
        if (count($rowMatches[1] ?? []) > 1_001) {
            throw new RuntimeException('Excelはヘッダーを含め1001行以内にしてください');
        }
        $rows = [];
        foreach ($rowMatches[1] ?? [] as $rowXml) {
            preg_match_all('#<c\b([^>]*)>(.*?)</c>#s', $rowXml, $cellMatches, PREG_SET_ORDER);
            $row = [];
            foreach ($cellMatches as $cell) {
                $attributes = $cell[1];
                $content = $cell[2];
                if (!preg_match('#\br=["\']([A-Z]+)[0-9]+["\']#', $attributes, $ref)) {
                    continue;
                }
                $column = self::columnIndex($ref[1]);
                preg_match('#\bt=["\']([^"\']+)["\']#', $attributes, $typeMatch);
                $type = $typeMatch[1] ?? '';
                if ($type === 'inlineStr') {
                    preg_match_all('#<t\b[^>]*>(.*?)</t>#s', $content, $texts);
                    $value = implode('', array_map([self::class, 'decodeXml'], $texts[1] ?? []));
                } else {
                    preg_match('#<v\b[^>]*>(.*?)</v>#s', $content, $valueMatch);
                    $raw = self::decodeXml($valueMatch[1] ?? '');
                    $value = $type === 's' ? ($shared[(int) $raw] ?? '') : $raw;
                }
                $row[$column] = $value;
            }
            if ($row !== []) {
                $lastColumn = max(array_keys($row));
                $rows[] = array_map(static fn(int $column): string => $row[$column] ?? '', range(0, $lastColumn));
            }
        }
        return $rows;
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + ord($letter) - 64;
        }
        return $index - 1;
    }

    private static function decodeXml(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
