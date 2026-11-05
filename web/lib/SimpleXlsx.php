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
    /** @var array<int, array{name:string, rows:list<list<scalar>>, colWidths:array<int,float>, charts:list<array>}> */
    private array $sheets = [];

    /** @var array<string,int> シート名 → sheets のインデックス(addChart の宛先解決用)。 */
    private array $sheetIndexByName = [];

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
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'colWidths' => $widthByIndex, 'charts' => []];
        $this->sheetIndexByName[$name] = count($this->sheets) - 1;
    }

    /**
     * シートに棒グラフ/折れ線グラフを1つ差し込む。データはそのシート内のセル範囲を参照する。
     *
     * @param string $sheetName 追加先シート名(addSheet 済みのもの。31文字で切られた後の名前)
     * @param 'bar'|'line' $type グラフ種別
     * @param string $title グラフタイトル
     * @param array{col:int,from:int,to:int} $cat カテゴリ軸(項目名)の列と行範囲(1-based 行、0-based 列)
     * @param list<array{col:int,from:int,to:int,name:string}> $series 系列ごとの値の列・行範囲・凡例名
     * @param array{fromCol:int,fromRow:int,toCol:int,toRow:int} $anchor グラフを置くセル範囲(0-based)
     *
     * 範囲は addSheet した rows のインデックスに対応する。行は1-based(ヘッダ=1行目)、列は0-based。
     * 例: 2行目〜11行目・A列をカテゴリ → ['col'=>0,'from'=>2,'to'=>11]。
     */
    public function addChart(string $sheetName, string $type, string $title, array $cat, array $series, array $anchor): void
    {
        $name = mb_substr($sheetName, 0, 31);
        if (!isset($this->sheetIndexByName[$name])) {
            throw new \RuntimeException("addChart: シートが見つかりません: {$sheetName}");
        }
        if ($type !== 'bar' && $type !== 'line') {
            throw new \RuntimeException("addChart: 未対応のグラフ種別: {$type}");
        }
        $idx = $this->sheetIndexByName[$name];
        $this->sheets[$idx]['charts'][] = [
            'type' => $type, 'title' => $title, 'cat' => $cat, 'series' => $series, 'anchor' => $anchor,
        ];
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
        // グラフを持つシートごとに drawing と chart の Override を足す(連番はシート横断で一意)。
        $chartNo = 0;
        $drawingNo = 0;
        foreach ($this->sheets as $sheet) {
            if (empty($sheet['charts'])) { continue; }
            $drawingNo++;
            $ct .= '<Override PartName="/xl/drawings/drawing' . $drawingNo . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
            foreach ($sheet['charts'] as $_) {
                $chartNo++;
                $ct .= '<Override PartName="/xl/charts/chart' . $chartNo . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/>';
            }
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

        // xl/styles.xml — ヘッダ行(太字+白文字+青背景) + パーセント書式
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'  // fontId=0: 標準
            . '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font>'  // fontId=1: 太字+白文字(ヘッダ専用。青背景 fillId=2 と対で使う)
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
        $chartSeq = 0;    // chartN.xml の通し番号
        $drawingSeq = 0;  // drawingN.xml の通し番号
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $hasCharts = !empty($sheet['charts']);
            $sheetDrawingNo = 0;
            if ($hasCharts) {
                $drawingSeq++;
                $sheetDrawingNo = $drawingSeq;
            }
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

            // sheetViews は sheetData より前(OpenXML 仕様の要素順序)
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';

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

            // drawing は worksheet の末尾(autoFilter より後)に置く(OpenXML の要素順序)。
            if ($hasCharts) {
                $xml .= '<drawing r:id="rIdDrawing"/>';
            }

            $xml .= '</worksheet>';
            $zip->addFromString('xl/worksheets/sheet' . $n . '.xml', $xml);

            // グラフを持つシートは drawing/chart/各 rels を書き出す。
            if ($hasCharts) {
                // worksheet → drawing の rels
                $zip->addFromString('xl/worksheets/_rels/sheet' . $n . '.xml.rels',
                    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    . '<Relationship Id="rIdDrawing" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing' . $sheetDrawingNo . '.xml"/>'
                    . '</Relationships>'
                );

                // この drawing に載る各 chart の番号を採番し、drawing XML と chart XML・drawing rels を作る。
                $sheetName = $sheet['name'];
                $drawingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">';
                $drawingRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
                $localChartIdx = 0;
                foreach ($sheet['charts'] as $chart) {
                    $chartSeq++;
                    $localChartIdx++;
                    $relId = 'rIdChart' . $localChartIdx;
                    $drawingXml .= $this->chartAnchorXml($chart['anchor'], $relId, $localChartIdx);
                    $drawingRels .= '<Relationship Id="' . $relId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart' . $chartSeq . '.xml"/>';
                    $zip->addFromString('xl/charts/chart' . $chartSeq . '.xml', $this->chartXml($chart, $sheetName));
                }
                $drawingXml .= '</xdr:wsDr>';
                $drawingRels .= '</Relationships>';
                $zip->addFromString('xl/drawings/drawing' . $sheetDrawingNo . '.xml', $drawingXml);
                $zip->addFromString('xl/drawings/_rels/drawing' . $sheetDrawingNo . '.xml.rels', $drawingRels);
            }
        }

        $zip->close();
    }

    /**
     * drawing 内の1グラフぶんのアンカー(twoCellAnchor)と graphicFrame を返す。
     * @param array{fromCol:int,fromRow:int,toCol:int,toRow:int} $anchor 0-based セル範囲
     */
    private function chartAnchorXml(array $anchor, string $relId, int $frameId): string
    {
        return '<xdr:twoCellAnchor>'
            . '<xdr:from><xdr:col>' . (int) $anchor['fromCol'] . '</xdr:col><xdr:colOff>0</xdr:colOff>'
            . '<xdr:row>' . (int) $anchor['fromRow'] . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
            . '<xdr:to><xdr:col>' . (int) $anchor['toCol'] . '</xdr:col><xdr:colOff>0</xdr:colOff>'
            . '<xdr:row>' . (int) $anchor['toRow'] . '</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
            . '<xdr:graphicFrame macro="">'
            . '<xdr:nvGraphicFramePr>'
            . '<xdr:cNvPr id="' . ($frameId + 1) . '" name="Chart ' . $frameId . '"/>'
            . '<xdr:cNvGraphicFramePr/>'
            . '</xdr:nvGraphicFramePr>'
            . '<xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">'
            . '<c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="' . $relId . '"/>'
            . '</a:graphicData></a:graphic>'
            . '</xdr:graphicFrame>'
            . '<xdr:clientData/>'
            . '</xdr:twoCellAnchor>';
    }

    /**
     * 1グラフぶんの chartN.xml を返す(barChart または lineChart)。
     * カテゴリ軸と各系列の値は、同一シート内のセル範囲(f=Sheet!$C$2:$C$11)を参照する。
     * @param array{type:string,title:string,cat:array,series:list<array>} $chart
     */
    private function chartXml(array $chart, string $sheetName): string
    {
        $type = $chart['type'];
        $plotTag = $type === 'line' ? 'lineChart' : 'barChart';
        $sheetRef = $this->sheetRangePrefix($sheetName);

        $cat = $chart['cat'];
        $catRange = $sheetRef . '!' . $this->absRange($cat['col'], $cat['from'], $cat['to']);

        $seriesXml = '';
        foreach (array_values($chart['series']) as $si => $ser) {
            $valRange = $sheetRef . '!' . $this->absRange($ser['col'], $ser['from'], $ser['to']);
            $seriesXml .= '<c:ser>'
                . '<c:idx val="' . $si . '"/><c:order val="' . $si . '"/>'
                . '<c:tx><c:strRef><c:f></c:f><c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>' . $this->xmlEscape((string) ($ser['name'] ?? ('系列' . ($si + 1)))) . '</c:v></c:pt></c:strCache></c:strRef></c:tx>'
                . '<c:cat><c:strRef><c:f>' . $this->xmlEscape($catRange) . '</c:f></c:strRef></c:cat>'
                . '<c:val><c:numRef><c:f>' . $this->xmlEscape($valRange) . '</c:f></c:numRef></c:val>'
                . ($type === 'line' ? '<c:smooth val="0"/>' : '')
                . '</c:ser>';
        }

        $plotExtra = $type === 'line'
            ? '<c:marker val="1"/>'
            : '<c:barDir val="col"/><c:grouping val="clustered"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<c:chart>'
            . '<c:title><c:tx><c:rich><a:bodyPr/><a:p><a:r><a:t>' . $this->xmlEscape($chart['title']) . '</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title>'
            . '<c:autoTitleDeleted val="0"/>'
            . '<c:plotArea><c:layout/>'
            . '<c:' . $plotTag . '>'
            . $plotExtra
            . $seriesXml
            . '<c:axId val="111111111"/><c:axId val="222222222"/>'
            . '</c:' . $plotTag . '>'
            . '<c:catAx><c:axId val="111111111"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="b"/><c:crossAx val="222222222"/></c:catAx>'
            . '<c:valAx><c:axId val="222222222"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="l"/><c:crossAx val="111111111"/></c:valAx>'
            . '</c:plotArea>'
            . '<c:legend><c:legendPos val="b"/><c:overlay val="0"/></c:legend>'
            . '<c:plotVisOnly val="1"/>'
            . '</c:chart>'
            . '</c:chartSpace>';
    }

    /** セル範囲参照のシート名プレフィックス。空白や記号を含むならシングルクォートで囲む。 */
    private function sheetRangePrefix(string $sheetName): string
    {
        if (preg_match('/^[A-Za-z0-9_\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]+$/u', $sheetName)) {
            return $sheetName;
        }
        return "'" . str_replace("'", "''", $sheetName) . "'";
    }

    /** 列(0-based)と行範囲(1-based)から絶対参照 $C$2:$C$11 を作る。 */
    private function absRange(int $col, int $from, int $to): string
    {
        $c = $this->colLetter($col);
        return '$' . $c . '$' . $from . ':$' . $c . '$' . $to;
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
        // XML 1.0 で許可される制御文字は \x09, \x0A, \x0D のみ。それ以外を除去。
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
