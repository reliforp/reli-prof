<?php
/**
 * Analyze PHP serialize/unserialize memory amplification.
 *
 * Related to wikimedia/less.php#104: unserializing PHP objects uses ~4x
 * the memory of the serialized data. This is a PHP core behavior.
 *
 * We test this with a realistic scenario: a large nested data structure
 * that gets serialized and unserialized (common in caching).
 */

$pid = getmypid();
echo "PID: $pid\n\n";

// Build a large nested data structure (simulating an AST or config tree)
function buildTree(int $depth, int $breadth): object {
    $node = new stdClass();
    $node->type = 'node_' . rand(0, 100);
    $node->value = str_repeat('x', rand(10, 100));
    $node->attributes = ['line' => rand(1, 1000), 'col' => rand(1, 80)];
    $node->children = [];

    if ($depth > 0) {
        for ($i = 0; $i < $breadth; $i++) {
            $node->children[] = buildTree($depth - 1, $breadth);
        }
    }
    return $node;
}

$memBaseline = memory_get_usage(true);

echo "Building tree (depth=8, breadth=4 = ~87K nodes)...\n";
$tree = buildTree(8, 4);

$memAfterBuild = memory_get_usage(true);
echo "After build: " . round($memAfterBuild / 1024 / 1024, 2) . " MB\n";

echo "\nSerializing...\n";
$serialized = serialize($tree);
$serializedSize = strlen($serialized);
echo "Serialized size: " . round($serializedSize / 1024 / 1024, 2) . " MB\n";

$memAfterSerialize = memory_get_usage(true);
echo "Memory after serialize: " . round($memAfterSerialize / 1024 / 1024, 2) . " MB\n";

// Free original tree
unset($tree);
gc_collect_cycles();
$memAfterFree = memory_get_usage(true);
echo "Memory after freeing original: " . round($memAfterFree / 1024 / 1024, 2) . " MB\n";

echo "\nUnserializing...\n";
$restored = unserialize($serialized);
$memAfterUnserialize = memory_get_usage(true);
echo "Memory after unserialize: " . round($memAfterUnserialize / 1024 / 1024, 2) . " MB\n";
echo "Amplification: " . round($memAfterUnserialize / ($serializedSize / 1024 / 1024), 1) . "x serialized size\n";

// Keep both in memory for analysis
echo "\nKeeping serialized + unserialized in memory...\n";
echo "Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";

echo "\nREADY_FOR_ANALYSIS\n";
$stdin = fopen('php://stdin', 'r');
stream_set_blocking($stdin, false);
for ($w = 0; $w < 120; $w++) { if (fgets($stdin)) break; sleep(1); }
