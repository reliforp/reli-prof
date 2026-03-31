<?php
/**
 * Structural Deduplication Detection.
 *
 * Finds groups of objects that have identical "shape" — same class,
 * same property set, same child class composition, similar sizes.
 * These are candidates for sharing/flyweight optimization.
 *
 * Approach: compute a "shape hash" per object based on:
 *   - class_name
 *   - sorted list of property link_names
 *   - sorted list of child class_names
 *   - shallow size
 *
 * Group by shape hash → groups with >N identical shapes = dedup candidates.
 *
 * Usage: php structural_dedup.php <sqlite3_path>
 */

$db_path = $argv[1] ?? '/tmp/reli_imap.sqlite3';
$run_id = 1;

$db = new PDO("sqlite:$db_path");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$start = microtime(true);

echo str_repeat("=", 70) . "\n";
echo " Structural Deduplication Report\n";
echo str_repeat("=", 70) . "\n\n";

// Query: for each object, compute its shape signature
// shape = class_name + property names + child types + shallow size
echo "Computing shape signatures...\n";
$t = microtime(true);

$shapes = $db->query("
    SELECT
        cnl.node_id,
        cnl.class_name,
        cnl.size,
        group_concat(e_prop.link_name, '|') as property_names
    FROM context_node_locations cnl
    JOIN context_edges e_to_obj ON e_to_obj.parent_node_id = cnl.node_id
        AND e_to_obj.link_name = 'object_properties' AND e_to_obj.is_tree = 1 AND e_to_obj.run_id = $run_id
    LEFT JOIN context_edges e_prop ON e_prop.parent_node_id = e_to_obj.child_node_id
        AND e_prop.is_tree = 1 AND e_prop.run_id = $run_id
    WHERE cnl.location_type = 'ZendObjectMemoryLocation'
        AND cnl.class_name IS NOT NULL
        AND cnl.run_id = $run_id
    GROUP BY cnl.node_id
")->fetchAll(PDO::FETCH_ASSOC);

echo "  " . count($shapes) . " objects analyzed, " . round(microtime(true) - $t, 2) . "s\n";

// Build shape hash groups
$shape_groups = [];
foreach ($shapes as $s) {
    // Normalize property list
    $props = $s['property_names'] ? explode('|', $s['property_names']) : [];
    sort($props);
    $prop_sig = implode(',', $props);

    // Shape = class + size + property signature
    $hash = $s['class_name'] . '|' . $s['size'] . '|' . $prop_sig;

    if (!isset($shape_groups[$hash])) {
        $shape_groups[$hash] = [
            'class' => $s['class_name'],
            'size' => (int)$s['size'],
            'props' => $prop_sig,
            'count' => 0,
            'total_size' => 0,
            'example_id' => $s['node_id'],
        ];
    }
    $shape_groups[$hash]['count']++;
    $shape_groups[$hash]['total_size'] += (int)$s['size'];
}

// Filter: only groups with many identical shapes
$dedup_candidates = array_filter($shape_groups, fn($g) => $g['count'] >= 50);
usort($dedup_candidates, fn($a, $b) => $b['total_size'] <=> $a['total_size']);

echo "\n--- Structural Dedup Candidates (≥50 identical shapes) ---\n\n";

if (empty($dedup_candidates)) {
    echo "  (none detected with ≥50 instances)\n";
} else {
    echo sprintf("%-50s %7s %8s %10s %s\n", "Class", "Count", "Each", "Total", "Properties");
    echo str_repeat("-", 110) . "\n";
    foreach (array_slice($dedup_candidates, 0, 15) as $g) {
        $short = preg_replace('/^.*\\\\/', '', $g['class']);
        $props = strlen($g['props']) > 30 ? substr($g['props'], 0, 27) . '...' : $g['props'];
        echo sprintf("%-50s %7d %7dB %9.2fKB %s\n",
            $short, $g['count'], $g['size'], $g['total_size'] / 1024, $props);
    }

    // Total savings if all identical shapes were shared (1 instance instead of N)
    $total_waste = 0;
    foreach ($dedup_candidates as $g) {
        // If shared: need 1 copy instead of N
        $total_waste += ($g['count'] - 1) * $g['size'];
    }
    echo sprintf("\n  Theoretical savings if all dedup candidates were shared: %.2f MB\n",
        $total_waste / 1024 / 1024);
    echo "  (This is the upper bound — actual savings depend on whether values also match.)\n";
}

// Also detect: objects with NO properties (empty shape) — pure overhead
echo "\n--- Empty Objects (no properties stored) ---\n\n";
$empty_objects = array_filter($shape_groups, fn($g) => $g['props'] === '' && $g['count'] >= 50);
usort($empty_objects, fn($a, $b) => $b['total_size'] <=> $a['total_size']);
if ($empty_objects) {
    foreach (array_slice($empty_objects, 0, 10) as $g) {
        $short = preg_replace('/^.*\\\\/', '', $g['class']);
        echo sprintf("  %s: %d instances × %dB = %.2f KB (no properties!)\n",
            $short, $g['count'], $g['size'], $g['total_size'] / 1024);
    }
} else {
    echo "  (none detected)\n";
}

echo "\nTotal: " . round(microtime(true) - $start, 2) . "s\n";
