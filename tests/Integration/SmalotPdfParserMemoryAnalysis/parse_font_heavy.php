<?php
/**
 * Parse a PDF with heavy font CMap tables using smalot/pdfparser.
 * Related to smalot/pdfparser#735.
 *
 * Usage:
 *   php parse_font_heavy.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use Smalot\PdfParser\Parser;

$pid = getmypid();
echo "PID: $pid\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n";

$pdfFile = __DIR__ . '/test_font_heavy.pdf';
if (!file_exists($pdfFile)) {
    echo "Generating test PDF...\n";
    require __DIR__ . '/generate_font_pdf.php';
}

echo "Parsing: $pdfFile\n";
echo "Memory before: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";

$parser = new Parser();
$document = $parser->parseFile($pdfFile);

echo "Memory after parse: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";

// Force font table loading by extracting text
$text = $document->getText();
echo "Text extracted (" . strlen($text) . " chars)\n";
echo "Memory after getText: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "Peak memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

// Examine fonts
$fonts = $document->getFonts();
echo "Fonts found: " . count($fonts) . "\n";
foreach ($fonts as $name => $font) {
    echo "  Font $name: " . get_class($font) . "\n";
}

echo "\nREADY_FOR_ANALYSIS\n";
echo "Attach: sudo php /home/user/reli-prof/reli inspector:memory -p $pid\n";
sleep(60);
