<?php
/**
 * Generate a PDF with large CMap/ToUnicode tables that trigger
 * massive $this->table growth in smalot/pdfparser Font.php.
 *
 * Related to smalot/pdfparser#735: the bfrange parsing creates
 * huge internal mapping tables when the CMap has broad Unicode ranges.
 */

$outputFile = __DIR__ . '/test_font_heavy.pdf';

// Build a PDF with a font that has a huge ToUnicode CMap
$pdf = "%PDF-1.4\n";

// Object 1: Catalog
$obj1 = strlen($pdf);
$pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";

// Object 2: Pages
$obj2 = strlen($pdf);
$pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";

// Object 3: Page with font reference
$obj3 = strlen($pdf);
$pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R /F2 6 0 R /F3 8 0 R /F4 10 0 R /F5 12 0 R /F6 14 0 R /F7 16 0 R /F8 18 0 R /F9 20 0 R /F10 22 0 R /F11 24 0 R /F12 26 0 R /F13 28 0 R /F14 30 0 R /F15 32 0 R /F16 34 0 R /F17 36 0 R /F18 38 0 R /F19 40 0 R /F20 42 0 R >> >> /Contents 14 0 R >>\nendobj\n";

// Create multiple fonts with large CMap tables
$nextObj = 4;
$fontOffsets = [];
$cmapOffsets = [];

for ($fontNum = 0; $fontNum < 20; $fontNum++) {
    $fontObjNum = $nextObj++;
    $cmapObjNum = $nextObj++;

    // Font object
    $fontOffsets[] = strlen($pdf);
    $pdf .= "$fontObjNum 0 obj\n<< /Type /Font /Subtype /Type0 /BaseFont /TestFont$fontNum /Encoding /Identity-H /DescendantFonts [<< /Type /Font /Subtype /CIDFontType2 /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> >>] /ToUnicode $cmapObjNum 0 R >>\nendobj\n";

    // CMap with many bfrange entries
    $cmap = "/CIDInit /ProcSet findresource begin\n";
    $cmap .= "12 dict begin\n";
    $cmap .= "begincmap\n";
    $cmap .= "/CMapType 2 def\n";
    $cmap .= "/CMapName /TestCMap$fontNum def\n";
    $cmap .= "1 begincodespacerange\n";
    $cmap .= "<0000> <FFFF>\n";
    $cmap .= "endcodespacerange\n";

    // Create bfrange entries with very broad Unicode ranges
    // This triggers massive $this->table growth in Font::loadTranslateTable()
    $numRanges = 100;
    $cmap .= "$numRanges beginbfrange\n";
    for ($r = 0; $r < $numRanges; $r++) {
        // Each range covers 655 codepoints for a total of 65,500 per font
        $start = $r * 655;
        $end = $start + 654;
        $destStart = 0x0020 + $r * 655;
        $cmap .= sprintf("<%04X> <%04X> <%04X>\n", $start, min($end, 0xFFFF), min($destStart, 0xFFFF));
    }
    $cmap .= "endbfrange\n";
    $cmap .= "endcmap\n";
    $cmap .= "CMapName currentdict /CMap defineresource pop\n";
    $cmap .= "end\nend\n";

    $cmapOffsets[] = strlen($pdf);
    $cmapLen = strlen($cmap);
    $pdf .= "$cmapObjNum 0 obj\n<< /Length $cmapLen >>\nstream\n$cmap\nendstream\nendobj\n";
}

// Content stream with text using the fonts
$contentObjNum = $nextObj++;
$content = "BT /F1 12 Tf (Hello World) Tj ET";
$contentOffset = strlen($pdf);
$contentLen = strlen($content);
$pdf .= "$contentObjNum 0 obj\n<< /Length $contentLen >>\nstream\n$content\nendstream\nendobj\n";

// Xref table
$xrefOffset = strlen($pdf);
$pdf .= "xref\n";
$pdf .= "0 " . ($nextObj) . "\n";
$pdf .= "0000000000 65535 f \n";
$pdf .= sprintf("%010d 00000 n \n", $obj1);
$pdf .= sprintf("%010d 00000 n \n", $obj2);
$pdf .= sprintf("%010d 00000 n \n", $obj3);

// Font and cmap objects (interleaved)
for ($i = 0; $i < 20; $i++) {
    $pdf .= sprintf("%010d 00000 n \n", $fontOffsets[$i]);
    $pdf .= sprintf("%010d 00000 n \n", $cmapOffsets[$i]);
}
$pdf .= sprintf("%010d 00000 n \n", $contentOffset);

$pdf .= "trailer\n<< /Size $nextObj /Root 1 0 R >>\nstartxref\n$xrefOffset\n%%EOF\n";

file_put_contents($outputFile, $pdf);
echo "Generated: $outputFile (" . round(strlen($pdf) / 1024, 2) . " KB)\n";
echo "Fonts: 5, each with 100 bfrange entries (256 chars per range = 25,600 entries per font)\n";
echo "Total table entries: ~128,000\n";
