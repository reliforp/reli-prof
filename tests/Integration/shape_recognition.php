<?php
/**
 * Shape Recognition: identify data structure patterns in the heap.
 *
 * Detects:
 * - Linked list (prev/next chains)
 * - Star (1 node → many children of same type)
 * - Companion pair (two classes with identical count)
 * - Hub (high fan-out node)
 * - Homogeneous collection (array of same-class objects)
 *
 * Uses only DB queries — no full graph load needed.
 *
 * Usage: php shape_recognition.php <sqlite3_path>
 */

$db_path = $argv[1] ?? '/tmp/reli_imap.sqlite3';
$run_id = 1;

$db = new PDO("sqlite:$db_path");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$start = microtime(true);

echo str_repeat("=", 70) . "\n";
echo " Shape Recognition Report\n";
echo str_repeat("=", 70) . "\n\n";

// Shape 1: Linked List Detection
// Look for link_names like "next", "prev", "previous", "parent", "child"
// where the same class references itself
echo "--- Linked List / Chain Patterns ---\n\n";
$chain_links = $db->query("
    SELECT
        e.link_name,
        cnl_parent.class_name as from_class,
        cnl_child.class_name as to_class,
        count(*) as cnt
    FROM context_edges e
    JOIN context_node_locations cnl_parent ON cnl_parent.node_id = e.parent_node_id
        AND cnl_parent.location_type = 'ZendObjectMemoryLocation' AND cnl_parent.run_id = $run_id
    JOIN context_node_locations cnl_child ON cnl_child.node_id = e.child_node_id
        AND cnl_child.location_type = 'ZendObjectMemoryLocation' AND cnl_child.run_id = $run_id
    WHERE e.run_id = $run_id
        AND cnl_parent.class_name = cnl_child.class_name
        AND cnl_parent.class_name IS NOT NULL
        AND e.link_name IN ('next', 'prev', 'previous', 'parent', 'firstChild',
            'lastChild', 'left', 'right', 'head', 'tail', 'successor', 'predecessor')
    GROUP BY e.link_name, cnl_parent.class_name
    HAVING count(*) > 10
    ORDER BY cnt DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if ($chain_links) {
    foreach ($chain_links as $c) {
        $short = preg_replace('/^.*\\\\/', '', $c['from_class']);
        echo sprintf("  %s::\$%s → self  (%d links) — %s chain\n",
            $short, $c['link_name'], $c['cnt'],
            in_array($c['link_name'], ['prev', 'previous', 'predecessor']) ? 'doubly-linked' : 'singly-linked');
    }
} else {
    echo "  (none detected)\n";
}

// Shape 2: Star Pattern (1 node type → many children of same type)
echo "\n--- Star / Hub Patterns ---\n\n";
$stars = $db->query("
    SELECT
        e.link_name,
        cn_parent.type as parent_type,
        cnl_child.class_name as child_class,
        count(*) as fan_out,
        round(sum(cnl_child.size) / 1024.0, 2) as children_kb
    FROM context_edges e
    JOIN context_nodes cn_parent ON cn_parent.node_id = e.parent_node_id AND cn_parent.run_id = $run_id
    JOIN context_node_locations cnl_child ON cnl_child.node_id = e.child_node_id
        AND cnl_child.location_type = 'ZendObjectMemoryLocation' AND cnl_child.run_id = $run_id
    WHERE e.is_tree = 1 AND e.run_id = $run_id
        AND cnl_child.class_name IS NOT NULL
    GROUP BY e.parent_node_id, cnl_child.class_name
    HAVING count(*) > 100
    ORDER BY count(*) DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if ($stars) {
    foreach ($stars as $s) {
        $short = preg_replace('/^.*\\\\/', '', $s['child_class']);
        echo sprintf("  %s → %d × %s (%s KB)\n",
            $s['parent_type'], $s['fan_out'], $short, $s['children_kb']);
    }
} else {
    echo "  (none detected)\n";
}

// Shape 3: Companion Pair Detection (two classes with count within 5%)
echo "\n--- Companion Object Patterns ---\n\n";
$companions = $db->query("
    SELECT
        a.class_name as class_a,
        b.class_name as class_b,
        a.count as count_a,
        b.count as count_b,
        round(a.memory_usage / 1024.0, 2) as mem_a_kb,
        round(b.memory_usage / 1024.0, 2) as mem_b_kb
    FROM class_objects_summary a
    JOIN class_objects_summary b ON a.run_id = b.run_id
        AND a.class_name < b.class_name
        AND abs(a.count - b.count) < a.count * 0.05
    WHERE a.run_id = $run_id AND a.count > 100
    ORDER BY a.memory_usage + b.memory_usage DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if ($companions) {
    foreach ($companions as $c) {
        $short_a = preg_replace('/^.*\\\\/', '', $c['class_a']);
        $short_b = preg_replace('/^.*\\\\/', '', $c['class_b']);
        $ratio = min($c['count_a'], $c['count_b']) / max($c['count_a'], $c['count_b']);
        echo sprintf("  %s (%d) ↔ %s (%d)  ratio=%.2f  total=%s KB\n",
            $short_a, $c['count_a'], $short_b, $c['count_b'],
            $ratio,
            round(($c['mem_a_kb'] + $c['mem_b_kb']), 2));
    }
} else {
    echo "  (none detected)\n";
}

// Shape 4: Homogeneous Collection (array containing mostly objects of one class)
echo "\n--- Homogeneous Collections ---\n\n";
$collections = $db->query("
    SELECT
        cnl.class_name,
        count(*) as cnt,
        round(sum(cnl.size) / 1024.0, 2) as total_kb,
        round(avg(cnl.size), 0) as avg_size
    FROM context_edges e_elem
    JOIN context_edges e_val ON e_val.parent_node_id = e_elem.child_node_id
        AND e_val.link_name = 'value' AND e_val.is_tree = 1 AND e_val.run_id = $run_id
    JOIN context_node_locations cnl ON cnl.node_id = e_val.child_node_id
        AND cnl.location_type = 'ZendObjectMemoryLocation' AND cnl.run_id = $run_id
    WHERE e_elem.is_tree = 1 AND e_elem.run_id = $run_id
        AND cnl.class_name IS NOT NULL
    GROUP BY e_elem.parent_node_id, cnl.class_name
    HAVING count(*) > 50
    ORDER BY sum(cnl.size) DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if ($collections) {
    foreach ($collections as $c) {
        $short = preg_replace('/^.*\\\\/', '', $c['class_name']);
        echo sprintf("  array of %d × %s (%s KB, avg %sB each)\n",
            $c['cnt'], $short, $c['total_kb'], $c['avg_size']);
    }
} else {
    echo "  (none detected)\n";
}

// Shape 5: Property shape fingerprint — classes with many identical property sets
echo "\n--- Property Shape Fingerprint (most uniform classes) ---\n\n";
$shapes = $db->query("
    SELECT
        cnl.class_name,
        count(DISTINCT cnl.node_id) as instance_count,
        count(DISTINCT e.link_name) as unique_properties,
        group_concat(DISTINCT e.link_name) as property_names
    FROM context_node_locations cnl
    JOIN context_edges e_obj ON e_obj.child_node_id = cnl.node_id
        AND e_obj.link_name = 'object_properties' AND e_obj.is_tree = 1 AND e_obj.run_id = $run_id
    JOIN context_edges e ON e.parent_node_id = e_obj.child_node_id
        AND e.is_tree = 1 AND e.run_id = $run_id
    WHERE cnl.location_type = 'ZendObjectMemoryLocation'
        AND cnl.class_name IS NOT NULL
        AND cnl.run_id = $run_id
    GROUP BY cnl.class_name
    HAVING count(DISTINCT cnl.node_id) > 50
    ORDER BY count(DISTINCT cnl.node_id) DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if ($shapes) {
    foreach ($shapes as $s) {
        $short = preg_replace('/^.*\\\\/', '', $s['class_name']);
        $props = strlen($s['property_names']) > 80
            ? substr($s['property_names'], 0, 77) . '...'
            : $s['property_names'];
        echo sprintf("  %s (%d instances, %d props): %s\n",
            $short, $s['instance_count'], $s['unique_properties'], $props);
    }
} else {
    echo "  (none detected)\n";
}

echo "\nTotal: " . round(microtime(true) - $start, 2) . "s\n";
