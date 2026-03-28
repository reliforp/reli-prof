<?php
/**
 * Analyze league/csv memory vs plain fgetcsv.
 * league/csv is supposed to be memory-efficient (streaming).
 */

require_once __DIR__ . '/vendor/autoload.php';

use League\Csv\Reader;
use League\Csv\Writer;

$pid = getmypid();
echo "PID: $pid\n\n";

// Generate a large CSV file
$csvFile = __DIR__ . '/test_large.csv';
$numRows = 50000;
$numCols = 20;

if (!file_exists($csvFile)) {
    echo "Generating CSV: $numRows rows × $numCols columns...\n";
    $writer = Writer::createFromPath($csvFile, 'w+');
    $header = array_map(fn($i) => "col_$i", range(0, $numCols - 1));
    $writer->insertOne($header);
    for ($r = 0; $r < $numRows; $r++) {
        $row = [];
        for ($c = 0; $c < $numCols; $c++) {
            $row[] = "data_{$r}_{$c}_" . bin2hex(random_bytes(5));
        }
        $writer->insertOne($row);
    }
    echo "Generated: " . round(filesize($csvFile) / 1024 / 1024, 2) . " MB\n";
}

$memBaseline = memory_get_usage(true);

// Read entire CSV into memory via league/csv
echo "\n=== league/csv Reader (getRecords → array) ===\n";
$reader = Reader::createFromPath($csvFile, 'r');
$reader->setHeaderOffset(0);

$records = [];
foreach ($reader->getRecords() as $record) {
    $records[] = $record;
}

$memAfterCsv = memory_get_usage(true);
echo "Records loaded: " . count($records) . "\n";
echo "Memory: " . round($memAfterCsv / 1024 / 1024, 2) . " MB\n";
echo "Delta: +" . round(($memAfterCsv - $memBaseline) / 1024 / 1024, 2) . " MB\n";
echo "Per row: ~" . round(($memAfterCsv - $memBaseline) / count($records)) . " bytes\n";

echo "\nREADY_FOR_ANALYSIS\n";
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
