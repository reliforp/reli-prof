<?php
/**
 * Reproduce PHPStan#8835 and analyze with reli-prof via self-diagnosis.
 *
 * PHPStan enters infinite recursion when resolving self::X in @implements.
 * We use register_shutdown_function to capture the OOM moment.
 */

$reliRoot = dirname(__DIR__, 3);
$outputDir = __DIR__;

register_shutdown_function(
    function () use ($reliRoot, $outputDir): void {
        $error = error_get_last();
        if (is_null($error)) return;
        if (strpos($error['message'], 'Allowed memory size of') !== 0) return;

        ini_set('memory_limit', '-1');
        $pid = getmypid();
        $timestamp = date('Ymd_His');
        $jsonFile = "{$outputDir}/phpstan_oom_{$timestamp}.json";
        $fileOpt = '--memory-limit-error-file=' . escapeshellarg($error['file']);
        $lineOpt = '--memory-limit-error-line=' . escapeshellarg((string)$error['line']);

        fwrite(STDERR, "\n=== PHPStan OOM DETECTED ===\n");
        fwrite(STDERR, "  {$error['file']}:{$error['line']}\n");
        fwrite(STDERR, "  Running reli-prof...\n");

        $reliCmd = PHP_BINARY . ' -d memory_limit=2G'
            . ' ' . escapeshellarg("{$reliRoot}/reli")
            . " inspector:memory -p {$pid} --no-stop-process"
            . " {$fileOpt} {$lineOpt}"
            . " > " . escapeshellarg($jsonFile) . " 2>/dev/null";

        system($reliCmd, $exitCode);

        if ($exitCode === 0 && file_exists($jsonFile) && filesize($jsonFile) > 100) {
            $json = json_decode(file_get_contents($jsonFile), true);
            if ($json && isset($json['summary'][0])) {
                $s = $json['summary'][0];
                fwrite(STDERR, sprintf("  Heap: %.2f MB\n", $s['zend_mm_heap_usage'] / 1024 / 1024));
            }
            if ($json && isset($json['class_objects_summary'])) {
                $classes = $json['class_objects_summary'];
                uasort($classes, fn($a, $b) => $b['count'] <=> $a['count']);
                fwrite(STDERR, "\n  TOP CLASSES BY COUNT:\n");
                $i = 0;
                foreach ($classes as $class => $info) {
                    if ($i++ >= 15) break;
                    $short = preg_replace('/^PHPStan\\\\/', 'PS\\', $class);
                    fwrite(STDERR, sprintf("  %7d × %-50s %8.1f KB\n",
                        $info['count'], $short, $info['memory_usage'] / 1024));
                }
            }
            if ($json && isset($json['location_types_summary'])) {
                $types = $json['location_types_summary'];
                uasort($types, fn($a, $b) => $b['memory_usage'] <=> $a['memory_usage']);
                fwrite(STDERR, "\n  TOP TYPES:\n");
                $i = 0;
                foreach ($types as $type => $info) {
                    if ($i++ >= 5) break;
                    fwrite(STDERR, sprintf("  %7d × %-40s %8.2f MB\n",
                        $info['count'], $type, $info['memory_usage'] / 1024 / 1024));
                }
            }
        } else {
            fwrite(STDERR, "  reli-prof failed or produced no output (exit: $exitCode)\n");
        }
        fwrite(STDERR, "=== END ===\n");
    }
);

// Run PHPStan on the problematic file
$phpstanBin = __DIR__ . '/vendor/bin/phpstan';
$targetFile = __DIR__ . '/test_self_const.php';

echo "Running PHPStan on $targetFile (will OOM due to infinite recursion)...\n";
echo "PID: " . getmypid() . "\n";

// PHPStan is a phar, run it via include
$_SERVER['argv'] = ['phpstan', 'analyse', '--level=max', '--no-progress', $targetFile];
$_SERVER['argc'] = count($_SERVER['argv']);

require $phpstanBin;
