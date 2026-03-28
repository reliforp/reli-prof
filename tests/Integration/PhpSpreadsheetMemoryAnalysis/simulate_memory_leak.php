<?php
/**
 * Analyze PhpSpreadsheet memory usage when creating large spreadsheets.
 *
 * PhpSpreadsheet is known for high memory consumption because it keeps
 * the entire cell data model in memory. Each cell is a Cell object with
 * value, style, coordinate, etc.
 *
 * This tests both creation and reading, with/without cell caching.
 */

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

$pid = getmypid();
echo "PID: $pid\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n\n";

$memBaseline = memory_get_usage(true);
echo "Baseline: " . round($memBaseline / 1024 / 1024, 2) . " MB\n\n";

// === Create a large spreadsheet ===
$rows = 10000;
$cols = 20;
echo "Creating spreadsheet: {$rows} rows × {$cols} columns = " . ($rows * $cols) . " cells\n";

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Header row
for ($c = 0; $c < $cols; $c++) {
    $col = chr(65 + $c); // A-T
    $sheet->setCellValue("{$col}1", "Column_{$c}");
}

// Data rows
for ($r = 2; $r <= $rows + 1; $r++) {
    for ($c = 0; $c < $cols; $c++) {
        $col = chr(65 + $c);
        if ($c === 0) {
            $sheet->setCellValue("{$col}{$r}", $r - 1);
        } elseif ($c < 5) {
            $sheet->setCellValue("{$col}{$r}", "Data_{$r}_{$c}_" . bin2hex(random_bytes(10)));
        } else {
            $sheet->setCellValue("{$col}{$r}", rand(1, 100000) / 100);
        }
    }

    if ($r % 2000 === 0) {
        $memNow = memory_get_usage(true);
        echo "  Row {$r}: " . round($memNow / 1024 / 1024, 2) . " MB\n";
    }
}

$memAfterCreate = memory_get_usage(true);
echo "\nAfter creation: " . round($memAfterCreate / 1024 / 1024, 2) . " MB\n";
echo "Delta: +" . round(($memAfterCreate - $memBaseline) / 1024 / 1024, 2) . " MB\n";
echo "Per cell: ~" . round(($memAfterCreate - $memBaseline) / ($rows * $cols)) . " bytes\n";

// Save to file
$filename = __DIR__ . '/test_large.xlsx';
$writer = new Xlsx($spreadsheet);
$writer->save($filename);
echo "Saved: " . round(filesize($filename) / 1024 / 1024, 2) . " MB\n";

$memAfterSave = memory_get_usage(true);
echo "After save: " . round($memAfterSave / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
echo "Attach: sudo php /home/user/reli-prof/reli inspector:memory -p $pid --output-format=sqlite3 --output=/tmp/reli_spreadsheet.sqlite3\n";
sleep(60);
