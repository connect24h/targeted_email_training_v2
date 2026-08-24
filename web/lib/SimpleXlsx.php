<?php declare(strict_types=1);

/**
 * ZipArchive + OpenXML 直書きの最小 XLSX ライター。
 * 外部ライブラリ依存なし。php8.4-zip 拡張が必要。
 *
 * Usage:
 *   $xlsx = new SimpleXlsx();
 *   $xlsx->addSheet('Sheet1', [['A','B'], [1,2]]);
 *   $xlsx->addSheet('Sheet2', [['X','Y'], [3,4]], ['X' => 14, 'Y' => 20]);
 *   $xlsx->download('report.xlsx');
 *   // or: $xlsx->saveToFile('/tmp/out.xlsx');
 */
class SimpleXlsx
{
    /** @var array<int, array{name:string, rows:list<list<scalar>>, colWidths:array<int,float>}> */
    private array $sheets = [];

    /**
     * シートを追加する。
     * @param string $name シート名(31文字まで)
     * @param list<list<scalar>> $rows 2次元配列。1行目はヘッダ想定。
     * @param array<string,float>|array<int,float> $colWidths カラム幅(文字数)。キーは列名(ヘッダ一致)または 0-based index。
     */
    public function addSheet(string $name, array $rows, array $colWidths = []): void
    {
        $name = mb_substr($name, 0, 31);
        // colWidths のキーが文字列(ヘッダ名)なら index に変換
        $widthByIndex = [];
        if ($rows && $colWidths) {
            $headers = $rows[0] ?? [];
            foreach ($colWidths as $k => $w) {
                if (is_int($k)) {
                    $widthByIndex[$k] = (float) $w;
                } else {
                    $idx = array_search($k, $headers, true);
                    if ($idx !== false) {
                        $widthByIndex[(int) $idx] = (float) $w;
                    }
                }
            }
        }
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'colWidths' => $widthByIndex];
    }

    /** ブラウザにダウンロードさせる。 */
    public function download(string $filename): never
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $this->build($tmp);
        http_response_code(200);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($tmp);
        unlink($tmp);
        exit;
    }

    /** ファイルに保存する。 */
    public function saveToFile(string $path): void
    {
        $this->build($path);
    }

    // ---- 以下 OpenXML 組み立て ----

    private function build(string $path): void
    {
        if (file_exists($path)) { unlink($path); }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("XLSX 作成に失敗: $path");
        }

        // [Content_Types].xml
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        foreach ($this->sheets as $i => $_) {
            $n = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);

        // _rels/.rels
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>'
        );

        // 共有文字列を構築
        $sst = [];  // string => index
        $sstList = [];
        foreach ($this->sheets as $sheet) {
            foreach ($sheet['rows'] as $row) {
                foreach ($row as $cell) {
                    if (is_string($cell) && !isset($sst[$cell])) {
                        $sst[$cell] = count($sstList);
                        $sstList[] = $cell;
                    }
                }
            }
        }

        // xl/sharedStrings.xml
        $ssXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sstList) . '" uniqueCount="' . count($sstList) . '">';
        foreach ($sstList as $s) {
            $ssXml .= '<si><t>' . $this->xmlEscape($s) . '</t></si>';
        }
        $ssXml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ssXml);

        // xl/styles.xml — ヘッダ行太字 + パーセント書式
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'  // fontId=0: 標準
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'  // fontId=1: 太字
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF4472C4"/></patternFill></fill>'  // fillId=2: 青背景
            . '</fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'          // s=0: 標準
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyFont="1" applyFill="1"><alignment horizontal="center"/></xf>'  // s=1: ヘッダ(太字+青背景)
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" applyNumberFormat="1"/>'  // s=2: パーセント
            . '</cellXfs>'
            . '</styleSheet>'
        );

        // xl/workbook.xml
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $wb .= '<sheet name="' . $this->xmlEscape($sheet['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        }
        $wb .= '</sheets></workbook>';
        $zip->addFromString('xl/workbook.xml', $wb);

        // xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $_) {
            $n = $i + 1;
            $wbRels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $wbRels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $wbRels .= '<Relationship Id="rIdSS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $wbRels .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 各シート
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

            // 列幅
            if ($sheet['colWidths']) {
                $xml .= '<cols>';
                foreach ($sheet['colWidths'] as $colIdx => $w) {
                    $c = $colIdx + 1;
                    $xml .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
                }
                $xml .= '</cols>';
            }

            $xml .= '<sheetData>';
            foreach ($sheet['rows'] as $ri => $row) {
                $rn = $ri + 1;
                $xml .= '<row r="' . $rn . '">';
                foreach ($row as $ci => $cell) {
                    $ref = $this->colLetter($ci) . $rn;
                    if ($ri === 0) {
                        // ヘッダ行: s=1(太字+青背景)
                        $xml .= '<c r="' . $ref . '" t="s" s="1"><v>' . ($sst[(string) $cell] ?? 0) . '</v></c>';
                    } elseif (is_string($cell)) {
                        $xml .= '<c r="' . $ref . '" t="s"><v>' . ($sst[$cell] ?? 0) . '</v></c>';
                    } elseif (is_float($cell) || (is_numeric($cell) && strpos((string) $cell, '.') !== false)) {
                        $xml .= '<c r="' . $ref . '"><v>' . $cell . '</v></c>';
                    } elseif (is_int($cell) || is_numeric($cell)) {
                        $xml .= '<c r="' . $ref . '"><v>' . $cell . '</v></c>';
                    } else {
                        $xml .= '<c r="' . $ref . '" t="s"><v>' . ($sst[(string) $cell] ?? 0) . '</v></c>';
                    }
                }
                $xml .= '</row>';
            }
            $xml .= '</sheetData>';

            // オートフィルタ(ヘッダ行がある場合)
            if (count($sheet['rows']) > 1) {
                $lastCol = $this->colLetter(count($sheet['rows'][0]) - 1);
                $lastRow = count($sheet['rows']);
                $xml .= '<autoFilter ref="A1:' . $lastCol . $lastRow . '"/>';
            }

            // ヘッダ行固定(フリーズ)
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

            $xml .= '</worksheet>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $xml);
        }

        $zip->close();
    }

    private function colLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26);
        }
        return $letter;
    }

    private function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
