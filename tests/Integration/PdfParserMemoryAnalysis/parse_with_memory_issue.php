<?php
/**
 * Script to reproduce memory exhaustion from PrinsFrank/pdfparser#301.
 *
 * This script parses a PDF with a deep PREV chain using PrinsFrank/pdfparser.
 * It will accumulate CrossReferenceSection objects in memory, demonstrating
 * the memory growth that can be analyzed with reli-prof.
 *
 * Usage:
 *   php parse_with_memory_issue.php
 *
 * For reli-prof analysis (from another terminal):
 *   sudo php reli inspector:memory -p <PID>
 */

require_once __DIR__ . '/vendor/autoload.php';

use PrinsFrank\PdfParser\PdfParser;

// Print PID for reli-prof to attach
$pid = getmypid();
echo "PID: $pid\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n";
echo "Starting PDF parse...\n";
echo "Attach reli-prof now: sudo php /home/user/reli-prof/reli inspector:memory -p $pid\n";

// Give time to attach reli-prof
sleep(3);

$pdfFile = __DIR__ . '/test_deep_prev_chain.pdf';
echo "Parsing: $pdfFile (" . round(filesize($pdfFile) / 1024 / 1024, 2) . " MB)\n";
echo "Memory before parse: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";

try {
    $parser = new PdfParser();
    $document = $parser->parseFile($pdfFile);
    echo "Parse completed successfully.\n";
    echo "Memory after parse: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
    echo "Peak memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

    // Keep process alive for reli-prof analysis
    echo "\nKeeping process alive for 30 seconds for memory analysis...\n";
    sleep(30);
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Memory at error: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
    echo "Peak memory at error: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

    // Keep process alive even after error for analysis
    echo "\nKeeping process alive for 30 seconds for memory analysis...\n";
    sleep(30);
}
