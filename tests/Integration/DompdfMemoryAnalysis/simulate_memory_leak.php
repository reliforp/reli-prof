<?php
/**
 * Test dompdf memory consumption with large HTML documents.
 * Related to Dinamiko/dk-pdf#113 and general dompdf memory issues.
 *
 * Dompdf builds a full DOM tree + CSS cascade + frame tree + page layout
 * in memory before rendering. This shows how memory scales with document size.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;

$pid = getmypid();
echo "PID: $pid\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n\n";

function generateLargeHtml(int $numParagraphs, int $numTables): string {
    $html = '<!DOCTYPE html><html><head><style>
        body { font-family: sans-serif; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        td, th { border: 1px solid #ccc; padding: 5px; }
        h2 { color: #333; }
        p { line-height: 1.6; }
    </style></head><body>';
    $html .= '<h1>Large Document for Memory Analysis</h1>';

    for ($i = 0; $i < $numParagraphs; $i++) {
        $html .= "<h2>Section $i</h2>";
        $html .= '<p>' . str_repeat("Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. ", 5) . '</p>';
    }

    for ($t = 0; $t < $numTables; $t++) {
        $html .= "<h2>Table $t</h2><table><tr><th>ID</th><th>Name</th><th>Value</th><th>Category</th></tr>";
        for ($r = 0; $r < 50; $r++) {
            $html .= "<tr><td>$r</td><td>Item $r</td><td>" . rand(100, 9999) . "</td><td>Cat " . ($r % 5) . "</td></tr>";
        }
        $html .= '</table>';
    }

    $html .= '</body></html>';
    return $html;
}

$memBaseline = memory_get_usage(true);
echo "Baseline: " . round($memBaseline / 1024 / 1024, 2) . " MB\n\n";

// Generate progressively larger documents
$tests = [
    ['paragraphs' => 100, 'tables' => 20],
    ['paragraphs' => 500, 'tables' => 100],
];

foreach ($tests as $test) {
    $html = generateLargeHtml($test['paragraphs'], $test['tables']);
    echo "HTML size: " . round(strlen($html) / 1024) . " KB ({$test['paragraphs']} paragraphs, {$test['tables']} tables)\n";

    $memBefore = memory_get_usage(true);
    $dompdf = new Dompdf();
    $dompdf->loadHtml($html);
    $memAfterLoad = memory_get_usage(true);
    echo "  After loadHtml: " . round($memAfterLoad / 1024 / 1024, 2) . " MB (+". round(($memAfterLoad - $memBefore) / 1024 / 1024, 2) . " MB)\n";

    $dompdf->render();
    $memAfterRender = memory_get_usage(true);
    echo "  After render: " . round($memAfterRender / 1024 / 1024, 2) . " MB (+" . round(($memAfterRender - $memBefore) / 1024 / 1024, 2) . " MB)\n";

    $pdf = $dompdf->output();
    $memAfterOutput = memory_get_usage(true);
    echo "  After output: " . round($memAfterOutput / 1024 / 1024, 2) . " MB, PDF size: " . round(strlen($pdf) / 1024) . " KB\n";

    unset($dompdf, $pdf, $html);
    gc_collect_cycles();
    echo "  After cleanup+GC: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n\n";
}

// Final large document — keep alive for reli-prof
echo "=== Final document for analysis ===\n";
$html = generateLargeHtml(500, 100);
echo "HTML size: " . round(strlen($html) / 1024) . " KB\n";

$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->render();

echo "Memory: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
echo "Attach: sudo php /home/user/reli-prof/reli inspector:memory -p $pid --output-format=sqlite3 --output=/tmp/reli_dompdf.sqlite3\n";
sleep(60);
