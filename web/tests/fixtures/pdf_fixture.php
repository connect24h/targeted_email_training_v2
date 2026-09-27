<?php
declare(strict_types=1);

/** 文字だけのページを持つ小さな PDF を組み立てる(テスト用)。 */
function tet2_test_make_pdf(array $pageTexts): string
{
    $objects = [];
    $n = count($pageTexts);
    $kids = [];
    for ($i = 0; $i < $n; $i++) {
        $kids[] = (4 + $i * 2) . ' 0 R';
    }
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    foreach ($pageTexts as $i => $text) {
        $pageId = 4 + $i * 2;
        $contentId = $pageId + 1;
        $stream = 'BT /F1 24 Tf 40 500 Td (' . $text . ') Tj ET';
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 420 595] /Resources << /Font << /F1 3 0 R >> >> /Contents '
            . $contentId . ' 0 R >>';
        $objects[$contentId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
    }
    ksort($objects);
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    return $pdf;
}
