<?php
$db_path = $argv[1] ?? '/tmp/reli_imap.sqlite3';
$run_id = 1;

$db = new PDO("sqlite:$db_path");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$start = microtime(true);

// Load ALL edges (tree + non-tree) — SCC needs the full graph
echo "Loading all edges...\n";
$t = microtime(true);
$rows = $db->query("SELECT parent_node_id, child_node_id FROM context_edges WHERE run_id = $run_id")->fetchAll(PDO::FETCH_NUM);
$children = [];
$all_nodes = [];
foreach ($rows as $r) {
    $parent = $r[0] === null ? -1 : (int)$r[0];
    $child = (int)$r[1];
    $children[$parent][] = $child;
    $all_nodes[$parent] = true;
    $all_nodes[$child] = true;
}
$edge_count = count($rows);
unset($rows);
echo "  $edge_count edges, " . count($all_nodes) . " nodes, " . round(microtime(true) - $t, 2) . "s\n";

// Load node sizes
$rows = $db->query("SELECT node_id, sum(size) as s FROM context_node_locations WHERE run_id = $run_id GROUP BY node_id")->fetchAll(PDO::FETCH_NUM);
$node_sizes = [];
foreach ($rows as $r) { $node_sizes[(int)$r[0]] = (int)$r[1]; }
unset($rows);

// Tarjan's SCC algorithm (iterative to avoid stack overflow)
echo "Running Tarjan's SCC...\n";
$t = microtime(true);

$index_counter = 0;
$stack = [];
$on_stack = [];
$index = [];
$lowlink = [];
$sccs = []; // list of [node_id, ...]

// Iterative Tarjan
$call_stack = []; // [(node, child_index)]
foreach (array_keys($all_nodes) as $v) {
    if (isset($index[$v])) continue;
    
    $call_stack = [[$v, 0]];
    $index[$v] = $lowlink[$v] = $index_counter++;
    $stack[] = $v;
    $on_stack[$v] = true;
    
    while ($call_stack) {
        [$node, $ci] = array_pop($call_stack);
        $node_children = $children[$node] ?? [];
        
        $found_unvisited = false;
        for ($i = $ci; $i < count($node_children); $i++) {
            $w = $node_children[$i];
            if (!isset($index[$w])) {
                // Push current back with incremented index, then push child
                $call_stack[] = [$node, $i + 1];
                $index[$w] = $lowlink[$w] = $index_counter++;
                $stack[] = $w;
                $on_stack[$w] = true;
                $call_stack[] = [$w, 0];
                $found_unvisited = true;
                break;
            } elseif (isset($on_stack[$w])) {
                $lowlink[$node] = min($lowlink[$node], $index[$w]);
            }
        }
        
        if (!$found_unvisited) {
            // All children processed — check if this is SCC root
            if ($lowlink[$node] === $index[$node]) {
                $scc = [];
                do {
                    $w = array_pop($stack);
                    unset($on_stack[$w]);
                    $scc[] = $w;
                } while ($w !== $node);
                if (count($scc) > 1) {
                    $sccs[] = $scc;
                }
            }
            // Update parent's lowlink
            if ($call_stack) {
                $parent_frame = &$call_stack[count($call_stack) - 1];
                $lowlink[$parent_frame[0]] = min($lowlink[$parent_frame[0]], $lowlink[$node]);
            }
        }
    }
}

echo "  SCC computation: " . round(microtime(true) - $t, 2) . "s\n";
echo "  SCCs with size > 1 (cycles): " . count($sccs) . "\n";

if (count($sccs) === 0) {
    echo "  No cycles found (DAG).\n";
} else {
    // Analyze each SCC
    echo "\n=== Strongly Connected Components (cycles) ===\n";
    usort($sccs, fn($a, $b) => count($b) <=> count($a));
    
    foreach (array_slice($sccs, 0, 10) as $i => $scc) {
        $scc_set = array_flip($scc);
        $total_size = 0;
        foreach ($scc as $node) {
            $total_size += $node_sizes[$node] ?? 0;
        }
        
        // Count external incoming and outgoing edges
        $ext_in = 0;
        $ext_out = 0;
        $ext_in_links = [];
        foreach ($scc as $node) {
            foreach ($children[$node] ?? [] as $child) {
                if (!isset($scc_set[$child])) $ext_out++;
            }
        }
        // For incoming: need reverse edges
        // Skip for now, just count outgoing
        
        echo sprintf("  SCC #%d: %d nodes, %.2f KB, %d outgoing edges\n",
            $i + 1, count($scc), $total_size / 1024, $ext_out);
        
        // Get class names of objects in this SCC
        $node_list = implode(',', array_slice($scc, 0, 100));
        $classes = $db->query("SELECT class_name, count(*) as cnt FROM context_node_locations WHERE node_id IN ($node_list) AND class_name IS NOT NULL AND run_id = $run_id GROUP BY class_name ORDER BY cnt DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($classes as $c) {
            echo "    {$c['cnt']}× {$c['class_name']}\n";
        }
    }
}

echo "\nTotal: " . round(microtime(true) - $start, 2) . "s, Peak: " . round(memory_get_peak_usage(true)/1024/1024) . " MB\n";
