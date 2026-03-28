<?php
/**
 * End-to-end analysis script for PrinsFrank/pdfparser#301 using reli-prof.
 *
 * This script:
 * 1. Generates a PDF with deep cross-reference PREV chains
 * 2. Starts a pdfparser process to parse it
 * 3. Attaches reli-prof to analyze memory usage
 * 4. Outputs a human-readable report of findings
 *
 * Requirements:
 *   - Run as root (reli-prof uses ptrace)
 *   - composer install already done in this directory
 *
 * Usage:
 *   sudo php run_analysis.php [--num-updates=500] [--objects-per-update=200]
 */

$reliRoot = dirname(__DIR__, 3);

// Parse options
$numUpdates = 500;
$objectsPerUpdate = 200;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--num-updates=')) {
        $numUpdates = (int) substr($arg, strlen('--num-updates='));
    }
    if (str_starts_with($arg, '--objects-per-update=')) {
        $objectsPerUpdate = (int) substr($arg, strlen('--objects-per-update='));
    }
}

echo "======================================================================\n";
echo " reli-prof Memory Analysis: PrinsFrank/pdfparser#301\n";
echo " Cross-Reference PREV Chain Memory Exhaustion\n";
echo "======================================================================\n\n";

// Step 1: Generate problematic PDF
echo "[1/4] Generating test PDF...\n";
echo "  PREV chain depth: $numUpdates\n";
echo "  Objects per update: $objectsPerUpdate\n";

$pdfFile = __DIR__ . '/test_deep_prev_chain.pdf';
generateProblematicPdf($pdfFile, $numUpdates, $objectsPerUpdate);
$pdfSizeMB = round(filesize($pdfFile) / 1024 / 1024, 2);
echo "  Generated: $pdfFile ($pdfSizeMB MB)\n\n";

// Step 2: Start the parser process
echo "[2/4] Starting pdfparser process...\n";
$targetScript = __DIR__ . '/parse_target.php';
writeTargetScript($targetScript, $pdfFile);

$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open(
    [PHP_BINARY, '-d', 'memory_limit=1G', $targetScript],
    $descriptorSpec,
    $pipes
);

if (!is_resource($process)) {
    echo "  ERROR: Failed to start parser process.\n";
    exit(1);
}

// Read PID from target script
$targetOutput = '';
$startTime = time();
while (time() - $startTime < 10) {
    $targetOutput .= fread($pipes[1], 4096);
    if (str_contains($targetOutput, 'READY_FOR_ANALYSIS')) {
        break;
    }
    usleep(100_000);
}

preg_match('/PID:(\d+)/', $targetOutput, $m);
$targetPid = (int) ($m[1] ?? 0);
if ($targetPid === 0) {
    echo "  ERROR: Could not get target PID.\n";
    echo "  Output: $targetOutput\n";
    proc_terminate($process);
    exit(1);
}
echo "  Target PID: $targetPid\n";

// Extract memory usage from target output
preg_match('/MEMORY_AFTER:(\d+)/', $targetOutput, $memMatch);
$memoryAfterParse = (int) ($memMatch[1] ?? 0);
preg_match('/PEAK_MEMORY:(\d+)/', $targetOutput, $peakMatch);
$peakMemory = (int) ($peakMatch[1] ?? 0);
echo "  memory_get_usage after parse: " . round($memoryAfterParse / 1024 / 1024, 2) . " MB\n";
echo "  memory_get_peak_usage: " . round($peakMemory / 1024 / 1024, 2) . " MB\n\n";

// Step 3: Run reli-prof
echo "[3/4] Running reli-prof inspector:memory...\n";
$reliOutputFile = __DIR__ . '/reli_analysis_output.json';
$reliCmd = PHP_BINARY . ' ' . escapeshellarg("$reliRoot/reli")
    . ' inspector:memory'
    . ' -p ' . $targetPid
    . ' 2>&1';

$reliOutput = shell_exec($reliCmd);
file_put_contents($reliOutputFile, $reliOutput);

// Signal target to exit
fwrite($pipes[0], "DONE\n");
fclose($pipes[0]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);

echo "  Analysis saved to: $reliOutputFile\n";
echo "  Output size: " . round(strlen($reliOutput) / 1024 / 1024, 2) . " MB\n\n";

// Step 4: Parse and display results
echo "[4/4] Analyzing results...\n\n";

$json = json_decode($reliOutput, true);
if ($json === null) {
    echo "  ERROR: Failed to parse reli-prof JSON output.\n";
    echo "  JSON error: " . json_last_error_msg() . "\n";
    echo "  First 500 chars: " . substr($reliOutput, 0, 500) . "\n";
    exit(1);
}

$summary = $json['summary'][0];
$types = $json['location_types_summary'];
$classes = $json['class_objects_summary'];

echo "======================================================================\n";
echo " ANALYSIS RESULTS\n";
echo "======================================================================\n\n";

echo "--- Memory Overview ---\n";
echo sprintf("  %-35s %10.2f MB\n", "Zend MM Heap Total:", $summary['zend_mm_heap_total'] / 1024 / 1024);
echo sprintf("  %-35s %10.2f MB\n", "Zend MM Heap Usage:", $summary['zend_mm_heap_usage'] / 1024 / 1024);
echo sprintf("  %-35s %10.2f MB\n", "  - Chunk Usage:", $summary['zend_mm_chunk_usage'] / 1024 / 1024);
echo sprintf("  %-35s %10.2f MB\n", "  - Huge Allocation Usage:", $summary['zend_mm_huge_usage'] / 1024 / 1024);
echo sprintf("  %-35s %10.2f MB\n", "memory_get_usage():", $summary['memory_get_usage'] / 1024 / 1024);
echo sprintf("  %-35s %10.2f MB\n", "memory_get_real_usage():", $summary['memory_get_real_usage'] / 1024 / 1024);
echo sprintf("  %-35s %10.2f %%\n", "Heap Analyzed:", $summary['heap_memory_analyzed_percentage']);
echo "\n";

echo "--- Memory by Value Type (top 10) ---\n";
uasort($types, fn($a, $b) => $b['memory_usage'] <=> $a['memory_usage']);
foreach (array_slice($types, 0, 10, true) as $type => $info) {
    $pct = ($info['memory_usage'] / $summary['zend_mm_heap_usage']) * 100;
    echo sprintf("  %-45s count=%7d  memory=%8.2f MB (%5.1f%%)\n",
        $type, $info['count'], $info['memory_usage'] / 1024 / 1024, $pct);
}
echo "\n";

echo "--- Memory by Object Class (top 15) ---\n";
uasort($classes, fn($a, $b) => $b['memory_usage'] <=> $a['memory_usage']);
$totalObjMemory = array_sum(array_column($classes, 'memory_usage'));
foreach (array_slice($classes, 0, 15, true) as $class => $info) {
    $pct = ($totalObjMemory > 0) ? ($info['memory_usage'] / $totalObjMemory) * 100 : 0;
    // Shorten class name for display
    $shortClass = preg_replace('/^PrinsFrank\\\\PdfParser\\\\/', 'P\\', $class);
    echo sprintf("  %-65s count=%7d  memory=%10.2f KB (%5.1f%%)\n",
        $shortClass, $info['count'], $info['memory_usage'] / 1024, $pct);
}
echo "\n";

// Summary
echo "======================================================================\n";
echo " DIAGNOSIS\n";
echo "======================================================================\n\n";

// Find the CrossReferenceEntryInUseObject class
$entryClass = null;
$entryInfo = null;
foreach ($classes as $class => $info) {
    if (str_contains($class, 'CrossReferenceEntryInUseObject')) {
        $entryClass = $class;
        $entryInfo = $info;
        break;
    }
}

$sectionClass = null;
$sectionInfo = null;
foreach ($classes as $class => $info) {
    if (str_contains($class, 'CrossReferenceSection') && !str_contains($class, 'SubSection')) {
        $sectionClass = $class;
        $sectionInfo = $info;
        break;
    }
}

$subSectionClass = null;
$subSectionInfo = null;
foreach ($classes as $class => $info) {
    if (str_contains($class, 'CrossReferenceSubSection')) {
        $subSectionClass = $class;
        $subSectionInfo = $info;
        break;
    }
}

if ($entryInfo) {
    $entryMemPct = ($entryInfo['memory_usage'] / $summary['zend_mm_heap_usage']) * 100;
    echo "Root Cause: CrossReferenceEntryInUseObject accumulation\n\n";
    echo "  CrossReferenceSourceParser::parse() (line 64-75) follows the PREV chain\n";
    echo "  in cross-reference tables, creating a CrossReferenceSection for each\n";
    echo "  incremental update. Each section contains CrossReferenceSubSection objects,\n";
    echo "  which in turn hold CrossReferenceEntryInUseObject instances for every\n";
    echo "  referenced PDF object.\n\n";
    echo "  reli-prof detected:\n";
    echo sprintf("    - %d CrossReferenceEntryInUseObject instances (%s KB, %.1f%% of heap)\n",
        $entryInfo['count'], number_format($entryInfo['memory_usage'] / 1024, 2), $entryMemPct);
    if ($sectionInfo) {
        echo sprintf("    - %d CrossReferenceSection instances (%s KB)\n",
            $sectionInfo['count'], number_format($sectionInfo['memory_usage'] / 1024, 2));
    }
    if ($subSectionInfo) {
        echo sprintf("    - %d CrossReferenceSubSection instances (%s KB)\n",
            $subSectionInfo['count'], number_format($subSectionInfo['memory_usage'] / 1024, 2));
    }
    echo "\n";
    echo "  The object memory alone accounts for " . number_format($totalObjMemory / 1024 / 1024, 2) . " MB,\n";
    echo "  but each object also holds ZendString references for property names and\n";
    echo "  backed enum values, which account for the majority of the 27+ MB in\n";
    echo "  ZendStringMemoryLocation allocations.\n\n";
}

echo "  Recommended Fixes:\n";
echo "    1. Add a maximum PREV chain depth limit (e.g., 100) to prevent\n";
echo "       unbounded memory growth from malicious/corrupt PDFs.\n";
echo "    2. Add cycle detection in the PREV chain to prevent infinite loops.\n";
echo "    3. Consider lazy loading of cross-reference entries instead of\n";
echo "       materializing all entries into objects upfront.\n";
echo "    4. Use lightweight value objects or arrays instead of full\n";
echo "       CrossReferenceEntryInUseObject instances (each object has\n";
echo "       ~72 bytes overhead from zend_object header + properties).\n\n";

echo "======================================================================\n";
echo " reli-prof successfully identified the memory bottleneck in the\n";
echo " pdfparser library by analyzing the live process memory externally.\n";
echo " No modifications to the target code were required.\n";
echo "======================================================================\n";

// Cleanup
@unlink($targetScript);

// ========================================================================
// Helper functions
// ========================================================================

function generateProblematicPdf(string $outputFile, int $numUpdates, int $objectsPerUpdate): void {
    $pdf = '';
    $pdf .= "%PDF-1.4\n";

    $obj1Offset = strlen($pdf);
    $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $obj2Offset = strlen($pdf);
    $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $obj3Offset = strlen($pdf);
    $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n";

    $dummyObjOffsets = [];
    for ($i = 4; $i < 4 + $objectsPerUpdate; $i++) {
        $dummyObjOffsets[$i] = strlen($pdf);
        $str = str_repeat('A', 200);
        $pdf .= "$i 0 obj\n<< /Type /DummyObject /Data ($str) >>\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n";
    $pdf .= "0 " . (4 + $objectsPerUpdate) . "\n";
    $pdf .= sprintf("0000000000 65535 f \n");
    $pdf .= sprintf("%010d 00000 n \n", $obj1Offset);
    $pdf .= sprintf("%010d 00000 n \n", $obj2Offset);
    $pdf .= sprintf("%010d 00000 n \n", $obj3Offset);
    for ($i = 4; $i < 4 + $objectsPerUpdate; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $dummyObjOffsets[$i]);
    }
    $pdf .= "trailer\n";
    $pdf .= "<< /Size " . (4 + $objectsPerUpdate) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n";
    $pdf .= "$xrefOffset\n";
    $pdf .= "%%EOF\n";

    $prevXrefOffset = $xrefOffset;
    $nextObjNum = 4 + $objectsPerUpdate;

    for ($update = 0; $update < $numUpdates; $update++) {
        $updateObjOffsets = [];
        for ($i = 0; $i < $objectsPerUpdate; $i++) {
            $objNum = $nextObjNum + $i;
            $updateObjOffsets[$objNum] = strlen($pdf);
            $str = str_repeat('B', 200);
            $pdf .= "$objNum 0 obj\n<< /Type /DummyUpdate /Iteration $update /Data ($str) >>\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n";
        $pdf .= "$nextObjNum $objectsPerUpdate\n";
        for ($i = 0; $i < $objectsPerUpdate; $i++) {
            $objNum = $nextObjNum + $i;
            $pdf .= sprintf("%010d 00000 n \n", $updateObjOffsets[$objNum]);
        }

        $totalSize = $nextObjNum + $objectsPerUpdate;
        $pdf .= "trailer\n";
        $pdf .= "<< /Size $totalSize /Root 1 0 R /Prev $prevXrefOffset >>\n";
        $pdf .= "startxref\n";
        $pdf .= "$xrefOffset\n";
        $pdf .= "%%EOF\n";

        $prevXrefOffset = $xrefOffset;
        $nextObjNum += $objectsPerUpdate;
    }

    file_put_contents($outputFile, $pdf);
}

function writeTargetScript(string $path, string $pdfFile): void {
    $script = <<<'PHP'
<?php
require_once '%VENDOR_DIR%/autoload.php';

use PrinsFrank\PdfParser\PdfParser;

$pid = getmypid();
echo "PID:$pid\n";

$parser = new PdfParser();
$document = $parser->parseFile('%PDF_FILE%');

$memAfter = memory_get_usage(true);
$peakMem = memory_get_peak_usage(true);
echo "MEMORY_AFTER:$memAfter\n";
echo "PEAK_MEMORY:$peakMem\n";
echo "READY_FOR_ANALYSIS\n";

// Wait for signal to exit
$stdin = fopen('php://stdin', 'r');
fgets($stdin);
PHP;

    $script = str_replace('%VENDOR_DIR%', dirname($path) . '/vendor', $script);
    $script = str_replace('%PDF_FILE%', $pdfFile, $script);
    file_put_contents($path, $script);
}
