<?php
$db_path = $argv[1] ?? '/tmp/reli_eloquent.sqlite3';
$run_id = 1;

$db = new PDO("sqlite:$db_path");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$start = microtime(true);

// Step 1: Load all node sizes into a map (node_id => total_size)
echo "Loading node sizes...\n";
$t = microtime(true);
$stmt = $db->query("SELECT node_id, sum(size) as total_size FROM context_node_locations WHERE run_id = $run_id GROUP BY node_id");
$node_sizes = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $node_sizes[(int)$row['node_id']] = (int)$row['total_size'];
}
echo "  " . count($node_sizes) . " nodes with locations, " . round(microtime(true) - $t, 2) . "s\n";

// Step 2: Load tree edges into adjacency list
echo "Loading tree edges...\n";
$t = microtime(true);
$rows = $db->query("SELECT parent_node_id, child_node_id, link_name FROM context_edges WHERE run_id = $run_id AND is_tree = 1")->fetchAll(PDO::FETCH_NUM);
$children = []; // parent_id => [[child_id, link_name], ...]
$roots = [];
foreach ($rows as $row) {
    $parent = $row[0] === null ? -1 : (int)$row[0];
    $child = (int)$row[1];
    $link = $row[2];
    $children[$parent][] = [$child, $link];
    if ($parent === -1) $roots[] = [$child, $link];
}
unset($rows);
echo "  " . array_sum(array_map('count', $children)) . " edges, " . round(microtime(true) - $t, 2) . "s\n";
echo "  Memory: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";

// Step 3: Compute subtree sizes via post-order DFS
echo "Computing subtree sizes (post-order DFS)...\n";
$t = microtime(true);
$subtree_sizes = []; // node_id => subtree total size

// Iterative post-order DFS
$stack = [];
$visited = [];
foreach ($roots as [$root_id, $root_name]) {
    $stack[] = [$root_id, false];
}

while ($stack) {
    [$node, $processed] = array_pop($stack);
    
    if ($processed) {
        // All children computed, now compute this node
        $size = $node_sizes[$node] ?? 0;
        if (isset($children[$node])) {
            foreach ($children[$node] as [$child, $link]) {
                $size += $subtree_sizes[$child] ?? 0;
            }
        }
        $subtree_sizes[$node] = $size;
    } else {
        // Push self (to process after children) then push children
        $stack[] = [$node, true];
        if (isset($children[$node])) {
            foreach ($children[$node] as [$child, $link]) {
                if (!isset($subtree_sizes[$child])) {
                    $stack[] = [$child, false];
                }
            }
        }
    }
}
echo "  " . count($subtree_sizes) . " subtree sizes computed, " . round(microtime(true) - $t, 2) . "s\n";

// Step 4: Drill down from root — show heaviest branch at each level
echo "\n=== Bottleneck Drill-Down ===\n";
$current_children = $roots;
for ($depth = 0; $depth < 10; $depth++) {
    if (empty($current_children)) break;
    
    // Find heaviest child
    $heaviest = null;
    $heaviest_size = 0;
    $branches = [];
    foreach ($current_children as [$child_id, $link_name]) {
        $size = $subtree_sizes[$child_id] ?? 0;
        $branches[] = [$link_name, $child_id, $size];
        if ($size > $heaviest_size) {
            $heaviest_size = $size;
            $heaviest = [$child_id, $link_name];
        }
    }
    
    // Sort and show top 3
    usort($branches, fn($a, $b) => $b[2] <=> $a[2]);
    $indent = str_repeat("  ", $depth);
    foreach (array_slice($branches, 0, 3) as [$name, $id, $size]) {
        $marker = ($id === $heaviest[0]) ? "→ " : "  ";
        echo sprintf("%s%s%-40s %8.2f MB\n", $indent, $marker, $name, $size / 1024 / 1024);
    }
    if (count($branches) > 3) {
        echo sprintf("%s  ... +%d more branches\n", $indent, count($branches) - 3);
    }
    
    // Drill into heaviest
    $current_children = $children[$heaviest[0]] ?? [];
}

echo "\nTotal time: " . round(microtime(true) - $start, 2) . "s\n";
echo "Peak memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
