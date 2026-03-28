<?php
/**
 * Self-diagnosing OOM script using register_shutdown_function + reli-prof.
 *
 * As documented in docs/memory-profiler.md, reli-prof can analyze the
 * crashing process from within a shutdown function. This demonstrates
 * the technique with a real memory-exhausting scenario (dompdf rendering).
 *
 * Usage:
 *   sudo php self_diagnosing_oom.php
 *
 * The script will:
 * 1. Set a low memory limit
 * 2. Register a shutdown function that detects OOM
 * 3. Run dompdf on a large HTML document until OOM
 * 4. Automatically invoke reli-prof on itself at the moment of OOM
 * 5. Save results to a SQLite file for post-mortem analysis
 */

// No external dependencies needed for the simple OOM case

$reliRoot = dirname(__DIR__, 3);
$outputDir = __DIR__;

// === Step 1: Register shutdown function BEFORE doing anything else ===
register_shutdown_function(
    function () use ($reliRoot, $outputDir): void {
        $error = error_get_last();
        if (is_null($error)) {
            return;
        }
        if (strpos($error['message'], 'Allowed memory size of') !== 0) {
            return;
        }

        $pid = getmypid();
        $timestamp = date('Ymd_His');
        $jsonFile = "{$outputDir}/oom_analysis_{$timestamp}.json";
        $fileOpt = '--memory-limit-error-file=' . escapeshellarg($error['file']);
        $lineOpt = '--memory-limit-error-line=' . escapeshellarg((string)$error['line']);

        fwrite(STDERR, "\n");
        fwrite(STDERR, "=== OOM DETECTED ===\n");
        fwrite(STDERR, "  Error: {$error['message']}\n");
        fwrite(STDERR, "  File: {$error['file']}:{$error['line']}\n");
        fwrite(STDERR, "  PID: {$pid}\n");

        // Note: reli-prof runs as a separate process via system(), so it has
        // its own memory space. But we need enough memory in THIS process
        // to parse the JSON output afterward. Raise our own limit first.
        ini_set('memory_limit', '-1');

        fwrite(STDERR, "  Running reli-prof self-analysis...\n");

        // Invoke reli-prof on our own process
        $stderrFile = "{$outputDir}/oom_reli_stderr_{$timestamp}.txt";
        $reliCmd = PHP_BINARY . ' -d memory_limit=2G'
            . ' ' . escapeshellarg("{$reliRoot}/reli")
            . " inspector:memory -p {$pid} --no-stop-process"
            . " {$fileOpt} {$lineOpt}"
            . " > " . escapeshellarg($jsonFile)
            . " 2> " . escapeshellarg($stderrFile);

        $exitCode = 0;
        system($reliCmd, $exitCode);

        if ($exitCode === 0 && file_exists($jsonFile)) {
            $size = round(filesize($jsonFile) / 1024 / 1024, 2);
            fwrite(STDERR, "  Analysis saved: {$jsonFile} ({$size} MB)\n");

            // Parse and show quick summary
            $json = json_decode(file_get_contents($jsonFile), true);
            if ($json && isset($json['summary'][0])) {
                $s = $json['summary'][0];
                fwrite(STDERR, "\n  === MEMORY SUMMARY AT OOM ===\n");
                fwrite(STDERR, sprintf("  Heap usage: %.2f MB\n", $s['zend_mm_heap_usage'] / 1024 / 1024));
                fwrite(STDERR, sprintf("  memory_get_usage: %.2f MB\n", $s['memory_get_usage'] / 1024 / 1024));
            }
            if ($json && isset($json['class_objects_summary'])) {
                $classes = $json['class_objects_summary'];
                uasort($classes, fn($a, $b) => $b['memory_usage'] <=> $a['memory_usage']);
                fwrite(STDERR, "\n  === TOP CLASSES BY MEMORY ===\n");
                $i = 0;
                foreach ($classes as $class => $info) {
                    if ($i++ >= 10) break;
                    fwrite(STDERR, sprintf("  %6d × %-45s %8.1f KB\n",
                        $info['count'], $class, $info['memory_usage'] / 1024));
                }
            }
            if ($json && isset($json['location_types_summary'])) {
                $types = $json['location_types_summary'];
                uasort($types, fn($a, $b) => $b['memory_usage'] <=> $a['memory_usage']);
                fwrite(STDERR, "\n  === TOP TYPES BY MEMORY ===\n");
                $i = 0;
                foreach ($types as $type => $info) {
                    if ($i++ >= 5) break;
                    fwrite(STDERR, sprintf("  %7d × %-40s %8.2f MB\n",
                        $info['count'], $type, $info['memory_usage'] / 1024 / 1024));
                }
            }
        } else {
            fwrite(STDERR, "  reli-prof failed (exit code: {$exitCode})\n");
            if (file_exists($stderrFile)) {
                fwrite(STDERR, "  stderr: " . file_get_contents($stderrFile) . "\n");
            }
            if (file_exists($jsonFile)) {
                fwrite(STDERR, "  stdout (first 500): " . substr(file_get_contents($jsonFile), 0, 500) . "\n");
            }
        }

        fwrite(STDERR, "\n=== END OOM ANALYSIS ===\n");
    }
);

// === Step 2: Set a low memory limit ===
ini_set('memory_limit', '8M');

echo "PID: " . getmypid() . "\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n\n";

// === Step 3: Allocate memory until OOM ===
// Simulate a realistic scenario: loading records into an array
echo "Loading records into memory until OOM...\n";

class Record {
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $data,
        public readonly array $tags,
    ) {}
}

$records = [];
for ($i = 0; ; $i++) {
    $records[] = new Record(
        id: $i,
        name: "Record #{$i}",
        data: str_repeat("payload-{$i}-", 20),
        tags: ['tag_a', 'tag_b', "tag_{$i}"],
    );
    if ($i % 5000 === 0 && $i > 0) {
        echo "  Loaded {$i} records, memory: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
    }
}

echo "Should not reach here\n";
