<?php
/**
 * Analyze PHPWord memory usage with large documents.
 * Compare with PhpSpreadsheet (which had IgnoredErrors companion overhead).
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

$pid = getmypid();
echo "PID: $pid\n\n";

$memBaseline = memory_get_usage(true);

$numSections = 50;
$paragraphsPerSection = 100;
echo "Creating document: $numSections sections × $paragraphsPerSection paragraphs\n";

$phpWord = new PhpWord();

for ($s = 0; $s < $numSections; $s++) {
    $section = $phpWord->addSection();
    $section->addTitle("Section $s", 1);

    for ($p = 0; $p < $paragraphsPerSection; $p++) {
        $textRun = $section->addTextRun();
        $textRun->addText("Paragraph $p in section $s. ", ['bold' => ($p % 5 === 0)]);
        $textRun->addText("More text with ", ['italic' => true]);
        $textRun->addText("formatting variations.", ['underline' => 'single']);

        if ($p % 20 === 0) {
            $section->addListItem("List item $p", 0);
        }
        if ($p % 50 === 0) {
            $table = $section->addTable();
            for ($r = 0; $r < 5; $r++) {
                $table->addRow();
                $table->addCell(2000)->addText("Cell $r-A");
                $table->addCell(2000)->addText("Cell $r-B");
                $table->addCell(2000)->addText("Cell $r-C");
            }
        }
    }

    if (($s + 1) % 10 === 0) {
        $memNow = memory_get_usage(true);
        echo "  Section $s: " . round($memNow / 1024 / 1024, 2) . " MB\n";
    }
}

$memAfter = memory_get_usage(true);
$totalElements = $numSections * $paragraphsPerSection * 3; // ~3 text runs per paragraph
echo "\nAfter creation: " . round($memAfter / 1024 / 1024, 2) . " MB\n";
echo "Delta: +" . round(($memAfter - $memBaseline) / 1024 / 1024, 2) . " MB\n";
echo "Approx elements: ~$totalElements\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
