<?php
/**
 * Reproduce mpdf/mpdf#1046 — font cache memory leak.
 *
 * Each Mpdf instance creates its own font cache. When creating multiple
 * PDFs in a loop, the cache duplicates and memory grows linearly.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Mpdf\Mpdf;

$pid = getmypid();
echo "PID: $pid\n\n";

$memBaseline = memory_get_usage(true);
echo "Baseline: " . round($memBaseline / 1024 / 1024, 2) . " MB\n\n";

$numPdfs = 20;
echo "Creating $numPdfs PDFs in a loop (each with new Mpdf instance)...\n";

for ($i = 0; $i < $numPdfs; $i++) {
    $mpdf = new Mpdf(['tempDir' => '/tmp/mpdf_' . $pid]);

    $html = "<h1>Document $i</h1>";
    $html .= "<p>This is a test document with some <strong>bold</strong> and <em>italic</em> text.</p>";
    $html .= "<table><tr><th>Col A</th><th>Col B</th></tr>";
    for ($r = 0; $r < 20; $r++) {
        $html .= "<tr><td>Row $r</td><td>" . rand(100, 999) . "</td></tr>";
    }
    $html .= "</table>";

    $mpdf->WriteHTML($html);
    $mpdf->Output('/dev/null', \Mpdf\Output\Destination::FILE);

    // Explicitly clean up
    unset($mpdf);

    $memNow = memory_get_usage(true);
    $delta = $memNow - $memBaseline;
    echo sprintf("  PDF %2d: %.2f MB (delta: +%.2f MB)\n",
        $i + 1, $memNow / 1024 / 1024, $delta / 1024 / 1024);
}

echo "\nFinal: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\ngc_collect_cycles...\n";
$collected = gc_collect_cycles();
echo "Collected: $collected cycles\n";
echo "After GC (real): " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
echo "After GC (usage): " . round(memory_get_usage(false) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";

$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
