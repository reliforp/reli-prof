<?php
$db_path = $argv[1] ?? '/tmp/reli_eloquent.sqlite3';
$run_id = 1;

$db = new PDO("sqlite:$db_path");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$start = microtime(true);

// Step 1: Load node sizes
echo "Loading node sizes...\n";
$t = microtime(true);
$rows = $db->query("SELECT node_id, sum(size) as s FROM context_node_locations WHERE run_id = $run_id GROUP BY node_id")->fetchAll(PDO::FETCH_NUM);
$node_sizes = [];
foreach ($rows as $r) { $node_sizes[(int)$r[0]] = (int)$r[1]; }
unset($rows);
echo "  " . count($node_sizes) . " nodes, " . round(microtime(true) - $t, 2) . "s\n";

// Step 2: Load INT-ONLY adjacency (no link_name — saves 95% memory)
echo "Loading tree edges (int-only)...\n";
$t = microtime(true);
$rows = $db->query("SELECT parent_node_id, child_node_id FROM context_edges WHERE run_id = $run_id AND is_tree = 1")->fetchAll(PDO::FETCH_NUM);
$children = [];
$roots = [];
foreach ($rows as $r) {
    $parent = $r[0] === null ? -1 : (int)$r[0];
    $child = (int)$r[1];
    $children[$parent][] = $child;
    if ($parent === -1) $roots[] = $child;
}
$edge_count = count($rows);
unset($rows);
echo "  $edge_count edges, " . round(microtime(true) - $t, 2) . "s\n";
echo "  Memory: " . round(memory_get_usage(true) / 1024 / 1024, 0) . " MB\n";

// Step 3: Post-order DFS
echo "Computing subtree sizes...\n";
$t = microtime(true);
$subtree_sizes = [];
$stack = [];
foreach ($roots as $root_id) { $stack[] = [$root_id, false]; }
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
            if (!isset($subtree_sizes[$child])) {
                $stack[] = [$child, false];
            }
        }
    }
}
echo "  " . count($subtree_sizes) . " sizes, " . round(microtime(true) - $t, 2) . "s\n";

// Step 4: Drill-down — only fetch link_names for nodes we actually visit
echo "\n=== Bottleneck Drill-Down ===\n";

// Prepare a statement to fetch link_name on demand
$link_stmt = $db->prepare("SELECT link_name FROM context_edges WHERE run_id = ? AND parent_node_id IS NULL AND child_node_id = ? AND is_tree = 1 LIMIT 1");
$link_stmt_child = $db->prepare("SELECT link_name FROM context_edges WHERE run_id = ? AND child_node_id = ? AND is_tree = 1 LIMIT 1");

function get_link_name($db, $run_id, $node_id, $link_stmt_child) {
    $link_stmt_child->execute([$run_id, $node_id]);
    $r = $link_stmt_child->fetch(PDO::FETCH_NUM);
    return $r ? $r[0] : '?';
}

$current_children = $roots;
$current_is_root = true;
for ($depth = 0; $depth < 12; $depth++) {
    if (empty($current_children)) break;
    
    $branches = [];
    foreach ($current_children as $child_id) {
        $size = $subtree_sizes[$child_id] ?? 0;
        $branches[] = [$child_id, $size];
    }
    usort($branches, fn($a, $b) => $b[1] <=> $a[1]);
    
    $indent = str_repeat("  ", $depth);
    foreach (array_slice($branches, 0, 3) as $i => [$id, $size]) {
        $name = get_link_name($db, $run_id, $id, $link_stmt_child);
        $marker = ($i === 0) ? "→ " : "  ";
        echo sprintf("%s%s%-40s %8.2f MB\n", $indent, $marker, $name, $size / 1024 / 1024);
    }
    if (count($branches) > 3) {
        echo sprintf("%s  ... +%d more\n", $indent, count($branches) - 3);
    }
    
    $heaviest_id = $branches[0][0];
    $current_children = $children[$heaviest_id] ?? [];
}

echo "\nTotal: " . round(microtime(true) - $start, 2) . "s, Peak: " . round(memory_get_peak_usage(true) / 1024 / 1024, 0) . " MB\n";
