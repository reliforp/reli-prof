<?php
/**
 * SCC analysis v2: enriched with incoming paths, shape grouping, and finding-first output.
 *
 * Usage: php scc_analysis_v2.php <sqlite3_path>
 */

$db_path = $argv[1] ?? '/tmp/reli_imap.sqlite3';
$run_id = 1;

$db = new PDO("sqlite:$db_path");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$start = microtime(true);

// Step 1: Load all edges + build reverse graph
echo "Loading edges...\n";
$t = microtime(true);
$rows = $db->query("SELECT parent_node_id, child_node_id FROM context_edges WHERE run_id = $run_id")->fetchAll(PDO::FETCH_NUM);
$children = [];
$parents = [];  // reverse graph
$all_nodes = [];
foreach ($rows as $r) {
    $parent = $r[0] === null ? -1 : (int)$r[0];
    $child = (int)$r[1];
    $children[$parent][] = $child;
    $parents[$child][] = $parent;
    $all_nodes[$parent] = true;
    $all_nodes[$child] = true;
}
$edge_count = count($rows);
unset($rows);
echo "  $edge_count edges, " . count($all_nodes) . " nodes, " . round(microtime(true) - $t, 2) . "s\n";

// Step 2: Load node sizes and class names
$rows = $db->query("SELECT node_id, sum(size) as s, group_concat(DISTINCT class_name) as cls FROM context_node_locations WHERE run_id = $run_id GROUP BY node_id")->fetchAll(PDO::FETCH_NUM);
$node_sizes = [];
$node_classes = [];
foreach ($rows as $r) {
    $node_sizes[(int)$r[0]] = (int)$r[1];
    if ($r[2]) $node_classes[(int)$r[0]] = $r[2];
}
unset($rows);

// Step 3: Tarjan's SCC
echo "Running Tarjan's SCC...\n";
$t = microtime(true);

$index_counter = 0;
$stack = [];
$on_stack = [];
$index = [];
$lowlink = [];
$sccs = [];

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
            if ($call_stack) {
                $parent_frame = &$call_stack[count($call_stack) - 1];
                $lowlink[$parent_frame[0]] = min($lowlink[$parent_frame[0]], $lowlink[$node]);
            }
        }
    }
}
echo "  " . count($sccs) . " non-trivial SCCs, " . round(microtime(true) - $t, 2) . "s\n";

if (count($sccs) === 0) {
    echo "\n  No cycles found. Graph is a DAG.\n";
    echo "  → Retained size computation is exact.\n";
    echo "\nTotal: " . round(microtime(true) - $start, 2) . "s\n";
    exit(0);
}

// Step 4: Build SCC membership map
$node_to_scc = [];
foreach ($sccs as $scc_id => $scc) {
    foreach ($scc as $node) {
        $node_to_scc[$node] = $scc_id;
    }
}

// Step 5: Enrich each SCC
echo "Enriching SCCs...\n";
$scc_profiles = [];

// Prepare link_name lookup
$link_stmt = $db->prepare("SELECT link_name FROM context_edges WHERE child_node_id = ? AND run_id = $run_id LIMIT 1");

foreach ($sccs as $scc_id => $scc) {
    $scc_set = array_flip($scc);
    $total_size = 0;
    $class_counts = [];

    foreach ($scc as $node) {
        $total_size += $node_sizes[$node] ?? 0;
        $cls = $node_classes[$node] ?? null;
        if ($cls) {
            $class_counts[$cls] = ($class_counts[$cls] ?? 0) + 1;
        }
    }

    // External incoming edges (from outside SCC into SCC)
    $ext_in = 0;
    $ext_in_examples = [];
    foreach ($scc as $node) {
        foreach ($parents[$node] ?? [] as $parent) {
            if (!isset($scc_set[$parent]) && $parent !== -1) {
                $ext_in++;
                if (count($ext_in_examples) < 3) {
                    // Get link_name for representative path
                    $link_stmt->execute([$node]);
                    $link = $link_stmt->fetch(PDO::FETCH_NUM);
                    $link_name = $link ? $link[0] : '?';
                    $parent_cls = $node_classes[$parent] ?? '?';
                    $ext_in_examples[] = "$parent_cls → [$link_name]";
                }
            }
        }
    }

    // External outgoing
    $ext_out = 0;
    foreach ($scc as $node) {
        foreach ($children[$node] ?? [] as $child) {
            if (!isset($scc_set[$child])) $ext_out++;
        }
    }

    // Class composition signature (for grouping identical patterns)
    arsort($class_counts);
    $signature_parts = [];
    foreach ($class_counts as $cls => $cnt) {
        $short = preg_replace('/^.*\\\\/', '', $cls);
        $signature_parts[] = "$short:$cnt";
    }
    $signature = implode(', ', $signature_parts);

    $scc_profiles[$scc_id] = [
        'id' => $scc_id,
        'node_count' => count($scc),
        'total_size' => $total_size,
        'ext_in' => $ext_in,
        'ext_out' => $ext_out,
        'ext_in_examples' => $ext_in_examples,
        'class_counts' => $class_counts,
        'signature' => $signature,
        'single_owner_likelihood' => $ext_in <= 2 ? 'high' : ($ext_in <= 10 ? 'medium' : 'low'),
    ];
}

// Step 6: Group identical patterns
$pattern_groups = [];
foreach ($scc_profiles as $profile) {
    $sig = $profile['signature'];
    $pattern_groups[$sig][] = $profile;
}

// Step 7: Output
echo "\n" . str_repeat("=", 70) . "\n";
echo " SCC Analysis Report\n";
echo str_repeat("=", 70) . "\n\n";

echo "Overview:\n";
echo "  Total non-trivial SCCs: " . count($sccs) . "\n";
echo "  Unique SCC patterns: " . count($pattern_groups) . "\n";
$total_scc_mem = array_sum(array_column($scc_profiles, 'total_size'));
echo "  Total memory in cycles: " . round($total_scc_mem / 1024, 2) . " KB\n\n";

// Show grouped patterns (most impactful first)
$groups_sorted = [];
foreach ($pattern_groups as $sig => $group) {
    $total = array_sum(array_column($group, 'total_size'));
    $groups_sorted[] = ['sig' => $sig, 'group' => $group, 'total' => $total];
}
usort($groups_sorted, fn($a, $b) => $b['total'] <=> $a['total']);

echo "--- Cycle Patterns (grouped by class composition) ---\n\n";
foreach ($groups_sorted as $g) {
    $group = $g['group'];
    $example = $group[0];
    $count = count($group);

    if ($count > 1) {
        echo sprintf("  [%d identical cycles] %s\n", $count, $g['sig']);
    } else {
        echo sprintf("  [1 cycle] %s\n", $g['sig']);
    }
    echo sprintf("    Nodes/cycle: %d  |  Size/cycle: %.2f KB  |  Total: %.2f KB\n",
        $example['node_count'],
        $example['total_size'] / 1024,
        $g['total'] / 1024);
    echo sprintf("    Ext incoming: %d  |  Ext outgoing: %d  |  Single-owner: %s\n",
        $example['ext_in'], $example['ext_out'], $example['single_owner_likelihood']);

    if ($example['ext_in_examples']) {
        echo "    Entry points: " . implode('; ', $example['ext_in_examples']) . "\n";
    }

    // Finding-style interpretation
    if ($example['single_owner_likelihood'] === 'high') {
        echo "    → FINDING: Single entry point — breaking the owner reference likely frees this cycle.\n";
    } elseif ($count > 10) {
        echo "    → FINDING: $count identical cycles — a structural pattern, not accidental.\n";
    }
    echo "\n";
}

// DAG confidence
$dag_after_collapse = count($sccs) === 0;
echo "--- Retained Size Confidence ---\n";
if ($dag_after_collapse) {
    echo "  Graph is a DAG → retained size is EXACT.\n";
} else {
    echo "  " . count($sccs) . " cycles exist → retained size is approximate.\n";
    echo "  After SCC condensation, the graph becomes a DAG → retained size on condensed graph is EXACT.\n";
}

echo "\nTotal: " . round(microtime(true) - $start, 2) . "s, Peak: " . round(memory_get_peak_usage(true)/1024/1024) . " MB\n";
