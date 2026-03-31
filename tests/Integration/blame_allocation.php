<?php
/**
 * Blame Allocation: attribute shared memory to root owners.
 *
 * When a node is referenced from multiple paths, its memory can't be
 * attributed to a single owner. This pass distributes blame proportionally.
 *
 * Approach:
 * 1. Compute subtree sizes (tree-based, as in drill-down)
 * 2. For each "root owner" (top-level context: call_frames, class_table, etc.),
 *    compute the total exclusive + proportional shared memory
 * 3. For shared nodes (non-tree incoming > 0), split blame among all
 *    incoming paths proportionally to their subtree contribution
 *
 * Usage: php blame_allocation.php <sqlite3_path>
 */

$db_path = $argv[1] ?? '/tmp/reli_imap.sqlite3';
$run_id = 1;

$db = new PDO("sqlite:$db_path");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$start = microtime(true);

// Step 1: Load node sizes
echo "Loading...\n";
$rows = $db->query("SELECT node_id, sum(size) as s FROM context_node_locations WHERE run_id = $run_id GROUP BY node_id")->fetchAll(PDO::FETCH_NUM);
$node_sizes = [];
foreach ($rows as $r) { $node_sizes[(int)$r[0]] = (int)$r[1]; }
unset($rows);

// Step 2: Load ALL edges (tree + non-tree) for incoming count
$rows = $db->query("SELECT parent_node_id, child_node_id, is_tree FROM context_edges WHERE run_id = $run_id")->fetchAll(PDO::FETCH_NUM);
$tree_children = [];
$all_parents = [];  // child → [parent, ...]
$roots = [];
foreach ($rows as $r) {
    $parent = $r[0] === null ? -1 : (int)$r[0];
    $child = (int)$r[1];
    $is_tree = (int)$r[2];
    if ($is_tree) {
        $tree_children[$parent][] = $child;
        if ($parent === -1) $roots[] = $child;
    }
    $all_parents[$child][] = $parent;
}
unset($rows);
echo "  " . round(microtime(true) - $start, 2) . "s\n";

// Step 3: Post-order DFS for subtree sizes
echo "Computing subtree sizes...\n";
$subtree_sizes = [];
$stack = [];
foreach ($roots as $root) { $stack[] = [$root, false]; }
while ($stack) {
    [$node, $processed] = array_pop($stack);
    if ($processed) {
        $size = $node_sizes[$node] ?? 0;
        foreach ($tree_children[$node] ?? [] as $child) {
            $size += $subtree_sizes[$child] ?? 0;
        }
        $subtree_sizes[$node] = $size;
    } else {
        $stack[] = [$node, true];
        foreach ($tree_children[$node] ?? [] as $child) {
            if (!isset($subtree_sizes[$child])) $stack[] = [$child, false];
        }
    }
}

// Step 4: Compute incoming reference count per node (from ALL edges, not just tree)
$incoming_count = [];
foreach ($all_parents as $child => $parents) {
    $incoming_count[$child] = count($parents);
}

// Step 5: Assign each node to its "root owner" (which top-level branch it belongs to)
// BFS from each root, tracking which root branch each node belongs to
echo "Assigning root ownership...\n";
$node_root_owner = []; // node_id → root_node_id
$root_link_names = []; // root_node_id → link_name

$link_stmt = $db->prepare("SELECT link_name FROM context_edges WHERE child_node_id = ? AND is_tree = 1 AND parent_node_id IS NULL AND run_id = $run_id LIMIT 1");
foreach ($roots as $root) {
    $link_stmt->execute([$root]);
    $r = $link_stmt->fetch(PDO::FETCH_NUM);
    $root_link_names[$root] = $r ? $r[0] : "root_$root";

    // BFS
    $queue = [$root];
    $node_root_owner[$root] = $root;
    while ($queue) {
        $node = array_shift($queue);
        foreach ($tree_children[$node] ?? [] as $child) {
            if (!isset($node_root_owner[$child])) {
                $node_root_owner[$child] = $root;
                $queue[] = $child;
            }
        }
    }
}

// Step 6: Compute blame per root owner
// For each node:
//   - If incoming_count == 1: 100% blame to its tree parent's root owner
//   - If incoming_count > 1: split blame equally among all incoming parents' root owners
echo "Computing blame allocation...\n";

$blame = []; // root_node_id → [exclusive_bytes, shared_bytes, total_blamed]
foreach ($roots as $root) {
    $blame[$root] = ['exclusive' => 0, 'shared' => 0, 'nodes' => 0];
}

foreach ($node_sizes as $node => $size) {
    if ($size === 0) continue;

    $owner = $node_root_owner[$node] ?? null;
    if ($owner === null) continue;

    $in_count = $incoming_count[$node] ?? 1;

    if ($in_count <= 1) {
        $blame[$owner]['exclusive'] += $size;
        $blame[$owner]['nodes']++;
    } else {
        // Split blame among all incoming parents' root owners
        $owner_shares = [];
        foreach ($all_parents[$node] ?? [] as $parent) {
            if ($parent === -1) continue;
            $parent_owner = $node_root_owner[$parent] ?? null;
            if ($parent_owner !== null) {
                $owner_shares[$parent_owner] = ($owner_shares[$parent_owner] ?? 0) + 1;
            }
        }
        $total_shares = array_sum($owner_shares);
        if ($total_shares === 0) {
            $blame[$owner]['exclusive'] += $size;
        } else {
            foreach ($owner_shares as $share_owner => $share_count) {
                $fraction = $size * $share_count / $total_shares;
                if (!isset($blame[$share_owner])) {
                    $blame[$share_owner] = ['exclusive' => 0, 'shared' => 0, 'nodes' => 0];
                }
                $blame[$share_owner]['shared'] += $fraction;
            }
        }
    }
}

// Step 7: Output
echo "\n" . str_repeat("=", 70) . "\n";
echo " Blame Allocation Report\n";
echo str_repeat("=", 70) . "\n\n";

$total_heap = array_sum($node_sizes);
echo sprintf("Total tracked heap: %.2f MB\n\n", $total_heap / 1024 / 1024);

echo "--- Root Branch Blame ---\n\n";
echo sprintf("%-30s %10s %10s %10s %8s\n", "Root Branch", "Exclusive", "Shared", "Total", "% Heap");
echo str_repeat("-", 78) . "\n";

$blame_sorted = [];
foreach ($blame as $root => $b) {
    $total = $b['exclusive'] + $b['shared'];
    $blame_sorted[] = [
        'root' => $root,
        'name' => $root_link_names[$root] ?? "?",
        'exclusive' => $b['exclusive'],
        'shared' => $b['shared'],
        'total' => $total,
        'nodes' => $b['nodes'],
    ];
}
usort($blame_sorted, fn($a, $b) => $b['total'] <=> $a['total']);

foreach ($blame_sorted as $b) {
    if ($b['total'] < 1024) continue; // skip tiny
    $pct = $total_heap > 0 ? $b['total'] / $total_heap * 100 : 0;
    echo sprintf("%-30s %9.2fM %9.2fM %9.2fM %7.1f%%\n",
        $b['name'],
        $b['exclusive'] / 1024 / 1024,
        $b['shared'] / 1024 / 1024,
        $b['total'] / 1024 / 1024,
        $pct);
}

// Insight: shared fraction
$total_exclusive = array_sum(array_column($blame_sorted, 'exclusive'));
$total_shared = array_sum(array_column($blame_sorted, 'shared'));
echo sprintf("\nShared memory fraction: %.1f%%\n", $total_shared / max(1, $total_exclusive + $total_shared) * 100);
echo "(Low shared % → blame is reliable. High → multiple owners, blame is approximate.)\n";

echo "\nTotal: " . round(microtime(true) - $start, 2) . "s, Peak: " . round(memory_get_peak_usage(true)/1024/1024) . " MB\n";
