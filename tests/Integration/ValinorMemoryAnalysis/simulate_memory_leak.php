<?php
/**
 * Reproduce Valinor memory issue (CuyZ/Valinor#680).
 *
 * The bug: Using #[AsConverter] attribute causes excessive metadata
 * accumulation. Mapping 134K objects uses 888MB vs 36MB with a
 * ClosureModifier approach.
 *
 * Usage: php simulate_memory_leak.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use CuyZ\Valinor\Mapper\MappingError;
use CuyZ\Valinor\MapperBuilder;

$pid = getmypid();
echo "PID: $pid\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n\n";

// Simple readonly DTO
class SimpleDto {
    public function __construct(
        public readonly string $name,
        public readonly int $value,
        public readonly string $category,
    ) {}
}

$memBaseline = memory_get_usage(true);
echo "Baseline: " . round($memBaseline / 1024 / 1024, 2) . " MB\n\n";

// Map many objects with Valinor — the mapper accumulates internal metadata
echo "=== Valinor mapper with many objects ===\n";
$mapper = (new MapperBuilder())->mapper();

$numObjects = 10000; // Smaller than 134K to avoid actual OOM
echo "Mapping $numObjects objects...\n";

$results = [];
for ($i = 0; $i < $numObjects; $i++) {
    try {
        $result = $mapper->map(SimpleDto::class, [
            'name' => "Item $i",
            'value' => $i,
            'category' => 'test',
        ]);
        $results[] = $result;
    } catch (MappingError $e) {
        echo "Error at $i: " . $e->getMessage() . "\n";
        break;
    }

    if (($i + 1) % 2000 === 0) {
        $memNow = memory_get_usage(true);
        echo "  Mapped " . ($i+1) . ": " . round($memNow / 1024 / 1024, 2) . " MB\n";
    }
}

$memAfter1 = memory_get_usage(true);
echo "Memory after mapping: " . round($memAfter1 / 1024 / 1024, 2) . " MB\n";
echo "Delta: +" . round(($memAfter1 - $memBaseline) / 1024 / 1024, 2) . " MB\n\n";

// Keep results for analysis
echo "READY_FOR_ANALYSIS\n";
echo "Attach: sudo php /home/user/reli-prof/reli inspector:memory -p $pid --output-format=sqlite3 --output=/tmp/reli_valinor.sqlite3\n";
sleep(60);
