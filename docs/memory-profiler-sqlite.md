# SQLite Output for Memory Profiler

The memory profiler supports outputting analysis results to a SQLite database instead of JSON. This is useful for analyzing large memory snapshots where the JSON output would be too large to handle with tools like `jq`, and enables powerful ad-hoc querying with SQL.

## Quick Start

```bash
# Capture memory snapshot to SQLite
sudo ./reli inspector:memory -p <pid> -f sqlite3 -o memory.db

# Query the results
sqlite3 memory.db "SELECT * FROM location_types_summary ORDER BY memory_usage DESC"
```

## Command Options

The following options control SQLite output:

| Option | Short | Description |
|--------|-------|-------------|
| `--output-format` | `-f` | Set to `sqlite3` to enable SQLite output (default: `json`) |
| `--output` | `-o` | Output file path (required when using `sqlite3` format) |

All other options of `inspector:memory` work the same regardless of output format.

## Database Schema

### Summary Tables

#### `summary`
Key-value pairs containing metadata about the analysis snapshot.

| Column | Type | Description |
|--------|------|-------------|
| `key` | TEXT (PK) | Metric name (e.g. `zend_mm_heap_total`, `php_version`) |
| `value` | TEXT | Metric value |

```sql
SELECT * FROM summary;
```

#### `location_types_summary`
Aggregated memory usage by location type, computed via `GROUP BY` from `context_node_locations`.

| Column | Type | Description |
|--------|------|-------------|
| `type` | TEXT (PK) | Location type (e.g. `ZendStringMemoryLocation`, `ZendObjectMemoryLocation`) |
| `count` | INTEGER | Number of locations of this type |
| `memory_usage` | INTEGER | Total bytes consumed |

```sql
SELECT * FROM location_types_summary ORDER BY memory_usage DESC;
```

#### `class_objects_summary`
Aggregated memory usage by class, computed via `GROUP BY` from `context_node_locations`.

| Column | Type | Description |
|--------|------|-------------|
| `class_name` | TEXT (PK) | Fully qualified class name |
| `count` | INTEGER | Number of instances |
| `memory_usage` | INTEGER | Total bytes consumed |

```sql
SELECT * FROM class_objects_summary ORDER BY memory_usage DESC;
```

### Context Tree Tables

The context tree (reference graph) is stored in a normalized relational schema across three tables. This is equivalent to the `"context"` field in the JSON output.

#### `context_nodes`
The tree structure representing the memory reference hierarchy. Each node corresponds to a context in the JSON output's `"context"` field.

| Column | Type | Description |
|--------|------|-------------|
| `node_id` | INTEGER (PK) | Unique node identifier (same as `#node_id` in JSON) |
| `parent_node_id` | INTEGER | Parent node (NULL for root nodes) |
| `link_name` | TEXT | Name of the link from parent (e.g. property name, variable name, array key) |
| `type` | TEXT | Context type (e.g. `ObjectContext`, `ArrayHeaderContext`) |
| `reference_node_id` | INTEGER | If set, this node is a back-reference to another node (same as `#reference_node_id` in JSON) |

#### `context_node_locations`
Physical memory locations associated with nodes. A node may have multiple locations (e.g. an array has both a header and a table location).

| Column | Type | Description |
|--------|------|-------------|
| `id` | INTEGER (PK) | Auto-incremented row ID |
| `node_id` | INTEGER (FK) | References `context_nodes.node_id` |
| `address` | INTEGER | Memory address in the target process |
| `size` | INTEGER | Size in bytes |
| `location_type` | TEXT | Type of memory location (e.g. `ZendStringMemoryLocation`) |
| `class_name` | TEXT | Class name (for object locations) |
| `string_value` | TEXT | String value (for string locations) |
| `refcount` | INTEGER | Reference count |
| `type_info` | INTEGER | Zval type info flags |
| `region` | TEXT | Memory region (`zend_mm_heap`, `zend_mm_huge`, `vm_stack`, `compiler_arena`, or NULL) |

#### `context_node_attributes`
Key-value metadata attached to nodes (e.g. `#count` for array element counts).

| Column | Type | Description |
|--------|------|-------------|
| `id` | INTEGER (PK) | Auto-incremented row ID |
| `node_id` | INTEGER (FK) | References `context_nodes.node_id` |
| `key` | TEXT | Attribute name |
| `value` | TEXT | Attribute value |

### Indexes

The following indexes are created after bulk insertion for query performance:

- `idx_context_nodes_parent` on `context_nodes(parent_node_id)` -- essential for recursive CTE path queries and joins
- `idx_context_node_locations_node` on `context_node_locations(node_id)` -- essential for joining locations to nodes
- `idx_context_node_locations_class` on `context_node_locations(class_name)` -- speeds up class-based queries
- `idx_context_node_attributes_node` on `context_node_attributes(node_id)` -- for attribute lookups

### Views

#### `v_node_paths`
Computes the full reference path from root to each node using a recursive CTE. This view is convenient but can be slow on large databases.

| Column | Type | Description |
|--------|------|-------------|
| `node_id` | INTEGER | Node identifier |
| `path` | TEXT | Full path (e.g. `call_frames -> 0 -> local_variables -> myVar -> object_properties -> items`) |
| `depth` | INTEGER | Depth in the tree (0 = root) |

```sql
-- Find where a specific class is referenced
SELECT np.path, loc.size, loc.refcount
FROM v_node_paths np
JOIN context_node_locations loc ON loc.node_id = np.node_id
WHERE loc.class_name = 'App\\MyClass'
ORDER BY loc.size DESC;
```

> **Performance note**: For large databases (100K+ nodes), queries against `v_node_paths` can be slow because the recursive CTE is recomputed on every query. Use `inspector:memory:optimize-db` to materialize paths into a real table for faster queries.

#### `v_arrays`
Provides a convenient view of PHP array memory usage by joining array headers with their table allocations.

| Column | Type | Description |
|--------|------|-------------|
| `node_id` | INTEGER | Node identifier of the array header |
| `address` | INTEGER | Memory address |
| `header_size` | INTEGER | Size of the `zend_array` header |
| `table_size` | INTEGER | Size of the hash table allocation |
| `total_size` | INTEGER | `header_size + table_size` |
| `element_count` | INTEGER | Number of elements in the array |
| `refcount` | INTEGER | Reference count |

## Optimizing the Database: `inspector:memory:optimize-db`

For large databases, the `v_node_paths` view (recursive CTE) can be slow. The `inspector:memory:optimize-db` command materializes all node paths into a physical `node_paths` table for much faster queries.

```bash
./reli inspector:memory:optimize-db memory.db
```

This creates a `node_paths` table with the same schema as the `v_node_paths` view, plus an index on `depth`. After materialization, you can query `node_paths` directly instead of `v_node_paths`.

> **Note**: Materialization can significantly increase the database file size. For databases with deep reference trees, path strings can average several KB each, so the `node_paths` table may be much larger than the rest of the database combined.

### The `node_paths` table (after optimization)

| Column | Type | Description |
|--------|------|-------------|
| `node_id` | INTEGER (PK) | Node identifier |
| `path` | TEXT | Full path from root |
| `depth` | INTEGER | Depth in the tree |

Index: `idx_node_paths_depth` on `node_paths(depth)`

## Example Queries

### Top memory consumers by location type

```sql
SELECT type, count, memory_usage,
       printf('%.1f%%', memory_usage * 100.0 / (SELECT SUM(memory_usage) FROM location_types_summary)) AS pct
FROM location_types_summary
ORDER BY memory_usage DESC;
```

### Top memory-consuming classes

```sql
SELECT class_name, count, memory_usage,
       CAST(memory_usage / count AS INTEGER) AS avg_size
FROM class_objects_summary
ORDER BY memory_usage DESC
LIMIT 20;
```

### Duplicate strings (candidates for interning)

```sql
SELECT string_value,
       COUNT(*) AS copies,
       SUM(size) AS total_bytes,
       size AS per_copy_bytes
FROM context_node_locations
WHERE location_type = 'ZendStringMemoryLocation'
  AND string_value IS NOT NULL
GROUP BY string_value, size
HAVING COUNT(*) > 1
ORDER BY total_bytes DESC
LIMIT 20;
```

### High refcount values (widely shared structures)

```sql
SELECT location_type, class_name, printf('0x%x', address) AS hex_addr,
       size, refcount
FROM context_node_locations
WHERE refcount IS NOT NULL
ORDER BY refcount DESC
LIMIT 20;
```

### Memory distribution by tree depth

```sql
WITH RECURSIVE node_depth(node_id, depth) AS (
    SELECT node_id, 0
    FROM context_nodes WHERE parent_node_id IS NULL
    UNION ALL
    SELECT cn.node_id, nd.depth + 1
    FROM context_nodes cn
    JOIN node_depth nd ON cn.parent_node_id = nd.node_id
)
SELECT depth,
       COUNT(DISTINCT nd.node_id) AS nodes,
       SUM(loc.size) AS total_bytes,
       printf('%.1f%%', SUM(loc.size) * 100.0 / (SELECT SUM(size) FROM context_node_locations)) AS pct
FROM node_depth nd
JOIN context_node_locations loc ON loc.node_id = nd.node_id
GROUP BY depth
ORDER BY depth
LIMIT 30;
```

### Empty arrays (allocated but unused)

```sql
SELECT va.node_id, va.total_size, va.element_count, va.refcount,
       np.path
FROM v_arrays va
JOIN v_node_paths np ON np.node_id = va.node_id
WHERE va.element_count = 0
ORDER BY va.total_size DESC
LIMIT 20;
```

### Back-references (shared or circular references)

Nodes with `reference_node_id` are back-references to already-visited nodes in the DFS traversal. This includes both truly circular references (A -> B -> A) and shared references (A -> C, B -> C). To distinguish between them, check whether `reference_node_id` points to an ancestor of the current node.

```sql
-- All back-references (shared + circular)
SELECT cn.node_id, cn.parent_node_id, cn.link_name,
       cn.reference_node_id
FROM context_nodes cn
WHERE cn.reference_node_id IS NOT NULL
LIMIT 20;
```

### Top root paths by memory consumption (depth <= 2)

```sql
WITH RECURSIVE subtree(node_id, root_path) AS (
    SELECT cn.node_id, cn.link_name
    FROM context_nodes cn WHERE parent_node_id IS NULL
    UNION ALL
    SELECT cn.node_id,
           CASE WHEN st.root_path = '' THEN cn.link_name
                ELSE st.root_path || ' -> ' || cn.link_name END
    FROM context_nodes cn
    JOIN subtree st ON cn.parent_node_id = st.node_id
),
root_two AS (
    SELECT node_id,
           CASE
             WHEN instr(root_path, ' -> ') > 0
               THEN substr(root_path, 1,
                    CASE WHEN instr(substr(root_path, instr(root_path, ' -> ') + 4), ' -> ') > 0
                         THEN instr(root_path, ' -> ') + 3 + instr(substr(root_path, instr(root_path, ' -> ') + 4), ' -> ') - 1
                         ELSE length(root_path) END)
             ELSE root_path
           END AS root_prefix
    FROM subtree
)
SELECT root_prefix,
       COUNT(DISTINCT rt.node_id) AS nodes,
       SUM(loc.size) AS total_bytes,
       printf('%.1f%%', SUM(loc.size) * 100.0 / (SELECT SUM(size) FROM context_node_locations)) AS pct
FROM root_two rt
JOIN context_node_locations loc ON loc.node_id = rt.node_id
GROUP BY root_prefix
ORDER BY total_bytes DESC
LIMIT 15;
```

### Largest strings (log buffers, serialized data)

```sql
SELECT size, refcount,
       printf('0x%x', address) AS hex_addr,
       substr(string_value, 1, 80) AS preview
FROM context_node_locations
WHERE location_type = 'ZendStringMemoryLocation'
ORDER BY size DESC
LIMIT 10;
```

### Array table waste (over-allocated hash tables)

```sql
SELECT va.node_id, va.element_count, va.header_size, va.table_size, va.total_size,
       CASE WHEN va.element_count > 0 THEN va.table_size / va.element_count ELSE NULL END AS bytes_per_elem
FROM v_arrays va
WHERE va.element_count > 0 AND va.table_size > 0
ORDER BY (CAST(va.table_size AS REAL) / va.element_count) DESC
LIMIT 15;
```

### Region x location type matrix

```sql
SELECT region, location_type,
       COUNT(*) AS count,
       SUM(size) AS total_bytes
FROM context_node_locations
WHERE region IS NOT NULL
GROUP BY region, location_type
ORDER BY total_bytes DESC
LIMIT 20;
```

### Find all references to a specific node (equivalent to JSON `#reference_node_id` lookup)

```sql
-- Find all references to node 42
SELECT cn.node_id, cn.parent_node_id, cn.link_name, cn.type
FROM context_nodes cn
WHERE cn.reference_node_id = 42 OR cn.node_id = 42;
```

## JSON vs SQLite: When to Use Which

| Aspect | JSON (`-f json`) | SQLite (`-f sqlite3`) |
|--------|------|---------|
| Output speed | Baseline | Faster (deferred summary computation) |
| File size | Can be very large for deep trees | More compact (normalized schema) |
| Queryability | `jq` (limited by depth, memory) | Full SQL with indexes |
| Tooling | `jq`, `gojq`, `jj` | `sqlite3` CLI, any SQLite client |
| Streaming | Pipe to stdout | Requires `-o` file path |
| Path queries | `jq path(...)` | `v_node_paths` view or `node_paths` table |
| Reference lookup | `jq` with `#reference_node_id` | `WHERE reference_node_id = ?` |
| Large snapshots | May exceed `jq` depth limit | Handles well with proper indexes |

For snapshots with more than ~100K nodes, the SQLite format is strongly recommended.
