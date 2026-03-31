<?php
$db_path = $argv[1] ?? '/tmp/reli_eloquent.sqlite3';
$run_id = 1;

$db = new PDO("sqlite:$db_path");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$start = microtime(true);

// Load node sizes
$rows = $db->query("SELECT node_id, sum(size) as s FROM context_node_locations WHERE run_id = $run_id GROUP BY node_id")->fetchAll(PDO::FETCH_NUM);
$node_sizes = [];
foreach ($rows as $r) { $node_sizes[(int)$r[0]] = (int)$r[1]; }
unset($rows);

// Load tree edges (int-only)
$rows = $db->query("SELECT parent_node_id, child_node_id FROM context_edges WHERE run_id = $run_id AND is_tree = 1")->fetchAll(PDO::FETCH_NUM);
$children = [];
foreach ($rows as $r) {
    $parent = $r[0] === null ? -1 : (int)$r[0];
    $children[$parent][] = (int)$r[1];
}
unset($rows);
echo "Loaded. " . round(microtime(true) - $start, 2) . "s\n";

// Post-order DFS → subtree sizes
$subtree_sizes = [];
$roots = $children[-1] ?? [];
$stack = [];
foreach ($roots as $root) { $stack[] = [$root, false]; }
while ($stack) {
    [$node, $processed] = array_pop($stack);
    if ($processed) {
        $size = $node_sizes[$node] ?? 0;
        foreach ($children[$node] ?? [] as $child) {
            $size += $subtree_sizes[$child] ?? 0;
        }
        $subtree_sizes[$node] = $size;
    } else {
        $stack[] = [$node, true];
        foreach ($children[$node] ?? [] as $child) {
            if (!isset($subtree_sizes[$child])) $stack[] = [$child, false];
        }
    }
}
echo "DFS done. " . round(microtime(true) - $start, 2) . "s\n";

// Find choke points: subtree_size >> shallow_size
// And filter for "meaningful" nodes (have a class name or are root-level)
echo "\n=== Choke Points (small node, big subtree) ===\n";
echo "(nodes where subtree > 1 MB and subtree/shallow > 10x)\n\n";

$chokepoints = [];
foreach ($subtree_sizes as $node => $subtree) {
    $shallow = $node_sizes[$node] ?? 0;
    if ($subtree < 1024 * 1024) continue; // skip small subtrees
    if ($shallow < 1) $shallow = 1; // avoid div by zero
    $ratio = $subtree / $shallow;
    if ($ratio > 10) {
        $chokepoints[] = [$node, $shallow, $subtree, $ratio];
    }
}
usort($chokepoints, fn($a, $b) => $b[2] <=> $a[2]);

// For top choke points, get their context
$link_stmt = $db->prepare("SELECT link_name FROM context_edges WHERE child_node_id = ? AND is_tree = 1 AND run_id = $run_id LIMIT 1");
$type_stmt = $db->prepare("SELECT type FROM context_nodes WHERE node_id = ? AND run_id = $run_id LIMIT 1");
$loc_stmt = $db->prepare("SELECT class_name, location_type FROM context_node_locations WHERE node_id = ? AND run_id = $run_id LIMIT 1");

// Walk up 3 levels to get context
$parent_map = [];
$rows = $db->query("SELECT child_node_id, parent_node_id, link_name FROM context_edges WHERE is_tree = 1 AND run_id = $run_id")->fetchAll(PDO::FETCH_NUM);
foreach ($rows as $r) {
    $parent_map[(int)$r[0]] = [(int)$r[1], $r[2]];
}
unset($rows);

function get_path($node, $parent_map, $depth = 4) {
    $parts = [];
    $cur = $node;
    for ($i = 0; $i < $depth; $i++) {
        if (!isset($parent_map[$cur])) break;
        [$parent, $link] = $parent_map[$cur];
        $parts[] = $link;
        $cur = $parent;
    }
    return implode(' ← ', $parts);
}

foreach (array_slice($chokepoints, 0, 15) as [$node, $shallow, $subtree, $ratio]) {
    $type_stmt->execute([$node]);
    $type_row = $type_stmt->fetch(PDO::FETCH_NUM);
    $type = $type_row ? $type_row[0] : '?';

    $loc_stmt->execute([$node]);
    $loc_row = $loc_stmt->fetch(PDO::FETCH_NUM);
    $class = $loc_row[0] ?? '';
    $loc_type = $loc_row[1] ?? '';

    $path = get_path($node, $parent_map);
    $n_children = count($children[$node] ?? []);

    echo sprintf("  shallow=%5s  subtree=%8s  ratio=%6.0fx  children=%d\n",
        round($shallow) . 'B',
        round($subtree / 1024) . 'KB',
        $ratio,
        $n_children);
    echo sprintf("    type=%-30s class=%s\n", $type, $class ?: $loc_type);
    echo sprintf("    path: %s\n", $path ?: '(root)');
    echo "\n";
}

echo "Total: " . round(microtime(true) - $start, 2) . "s\n";
