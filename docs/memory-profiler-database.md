# Database Output for Memory Profiler

The memory profiler supports outputting analysis results to a relational database instead of JSON. This is useful for analyzing large memory snapshots where the JSON output would be too large to handle with tools like `jq`, and enables powerful ad-hoc querying with SQL.

Supported databases:
- **SQLite** (`-f sqlite3`) — local file, no server needed
- **MySQL** (`-f mysql`) — connect to an existing MySQL/MariaDB server
- **PostgreSQL** (`-f postgresql`) — connect to an existing PostgreSQL server

## Quick Start

### SQLite (local file)

```bash
sudo ./reli inspector:memory -p <pid> -f sqlite3 -o memory.db
sqlite3 memory.db "SELECT * FROM location_types_summary ORDER BY memory_usage DESC"
```

### MySQL

```bash
# Create the database first
mysql -u root -e "CREATE DATABASE reli_memory"

sudo ./reli inspector:memory -p <pid> -f mysql --db-name reli_memory --db-user root
mysql -u root reli_memory -e "SELECT * FROM location_types_summary ORDER BY memory_usage DESC"
```

### PostgreSQL

```bash
# Create the database first
createdb reli_memory

sudo ./reli inspector:memory -p <pid> -f postgresql --db-name reli_memory --db-user postgres
psql reli_memory -c "SELECT * FROM location_types_summary ORDER BY memory_usage DESC"
```

## Command Options

### Output format

| Option | Short | Description |
|--------|-------|-------------|
| `--output-format` | `-f` | Output format: `json` (default), `sqlite3`, `mysql`, `postgresql` |
| `--output` | `-o` | Output file path (required for `sqlite3`) |

### Database connection (for `mysql` / `postgresql`)

| Option | Default | Description |
|--------|---------|-------------|
| `--db-host` | `127.0.0.1` | Database server host |
| `--db-port` | `3306` / `5432` | Database server port (auto-detected from format) |
| `--db-name` | (required) | Database name |
| `--db-user` | (required) | Database user |
| `--db-password` | (empty) | Database password |

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

### Context Graph Tables

The context graph (reference graph) is stored as separate nodes and edges, enabling natural representation of shared references. This is equivalent to the `"context"` field in the JSON output.

#### `context_nodes`
Each node represents a unique context (object, array, string, etc.) in the memory graph.

| Column | Type | Description |
|--------|------|-------------|
| `node_id` | INTEGER (PK) | Unique node identifier (same as `#node_id` in JSON) |
| `type` | TEXT | Context type (e.g. `ObjectContext`, `ArrayHeaderContext`) |

#### `context_edges`
Edges represent parent-child relationships in the reference graph. A node may have multiple incoming edges (shared references).

| Column | Type | Description |
|--------|------|-------------|
| `parent_node_id` | INTEGER | Parent node (NULL for root nodes) |
| `child_node_id` | INTEGER | Child node (references `context_nodes.node_id`) |
| `link_name` | TEXT | Name of the link (e.g. property name, variable name, array key) |
| `is_tree` | INTEGER | `1` = DFS first-visit (spanning tree edge), `0` = back-reference (shared/circular) |

The `is_tree` flag distinguishes the DFS spanning tree from back-references:
- `is_tree = 1` edges form a tree where each node has exactly one parent — use these for canonical path queries
- `is_tree = 0` edges represent additional references to already-visited nodes — these capture shared and circular references

```sql
-- Find all places that reference node 42
SELECT parent_node_id, link_name, is_tree
FROM context_edges
WHERE child_node_id = 42;
```

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

- `idx_context_edges_parent_tree` on `context_edges(parent_node_id, is_tree)` -- essential for recursive CTE path queries
- `idx_context_edges_child` on `context_edges(child_node_id)` -- essential for "find all references to node X"
- `idx_context_node_locations_node` on `context_node_locations(node_id)` -- essential for joining locations to nodes
- `idx_context_node_locations_class` on `context_node_locations(class_name)` -- speeds up class-based queries
- `idx_context_node_attributes_node` on `context_node_attributes(node_id)` -- for attribute lookups

### Views

#### `v_node_paths`
Computes the canonical path from root to each node using a recursive CTE that follows only `is_tree = 1` edges. Each node has exactly one path. This view is convenient but can be slow on large databases.

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

> **Note**: This command currently supports SQLite databases only. For MySQL/PostgreSQL, you can run the equivalent `INSERT INTO ... WITH RECURSIVE` SQL manually.

> **Note**: Materialization can significantly increase the database size. For databases with deep reference trees, path strings can average several KB each, so the `node_paths` table may be much larger than the rest of the data combined.

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
    SELECT child_node_id, 0
    FROM context_edges WHERE parent_node_id IS NULL AND is_tree = 1
    UNION ALL
    SELECT e.child_node_id, nd.depth + 1
    FROM context_edges e
    JOIN node_depth nd ON e.parent_node_id = nd.node_id
    WHERE e.is_tree = 1
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

Edges with `is_tree = 0` are back-references to already-visited nodes in the DFS traversal. This includes both truly circular references (A -> B -> A) and shared references (A -> C, B -> C).

```sql
-- All back-references (shared + circular)
SELECT parent_node_id, child_node_id, link_name
FROM context_edges
WHERE is_tree = 0
LIMIT 20;
```

### Top root paths by memory consumption (depth <= 2)

```sql
WITH RECURSIVE subtree(node_id, root_path) AS (
    SELECT e.child_node_id, e.link_name
    FROM context_edges e WHERE e.parent_node_id IS NULL AND e.is_tree = 1
    UNION ALL
    SELECT e.child_node_id,
           CASE WHEN st.root_path = '' THEN e.link_name
                ELSE st.root_path || ' -> ' || e.link_name END
    FROM context_edges e
    JOIN subtree st ON e.parent_node_id = st.node_id
    WHERE e.is_tree = 1
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
-- Find all edges pointing to node 42 (both tree and back-references)
SELECT e.parent_node_id, e.link_name, e.is_tree
FROM context_edges e
WHERE e.child_node_id = 42;
```

### All non-circular paths to a specific node

```sql
-- All paths from root to node 42, avoiding cycles
WITH RECURSIVE cte(node_id, path, depth, visited) AS (
    SELECT child_node_id, link_name, 0, ',' || child_node_id || ','
    FROM context_edges WHERE parent_node_id IS NULL
  UNION ALL
    SELECT e.child_node_id,
           cte.path || ' -> ' || e.link_name,
           cte.depth + 1,
           cte.visited || e.child_node_id || ','
    FROM context_edges e
    JOIN cte ON e.parent_node_id = cte.node_id
    WHERE cte.visited NOT LIKE '%,' || e.child_node_id || ',%'
)
SELECT path, depth FROM cte
WHERE node_id = 42
LIMIT 100;
```

## Choosing an Output Format

| Aspect | JSON | SQLite | MySQL / PostgreSQL |
|--------|------|--------|-------------------|
| Setup | None | None | Requires a running server |
| Output speed | Baseline | Faster (deferred summaries) | Faster (deferred summaries) |
| File size | Large for deep trees | Compact (normalized) | N/A (server-side) |
| Queryability | `jq` (limited by depth) | Full SQL | Full SQL |
| Tooling | `jq`, `gojq`, `jj` | `sqlite3` CLI | `mysql` / `psql` CLI, any SQL client |
| Streaming | Pipe to stdout | Requires `-o` file path | Writes to remote server |
| Remote use | Must copy file | Must copy file | Direct write, no file transfer |
| Team sharing | Share file | Share file | Connect to shared server |
| Path queries | `jq path(...)` | `v_node_paths` / `node_paths` | `v_node_paths` / manual CTE |
| Reference lookup | `jq` + `#reference_node_id` | `context_edges WHERE child_node_id = ?` | Same |
| Large snapshots | May exceed `jq` depth limit | Handles well | Handles well |

**Recommendations**:
- **Local analysis**: SQLite is the simplest — no setup, single file, portable
- **Remote servers**: MySQL/PostgreSQL avoids copying large files off the server
- **Team collaboration**: MySQL/PostgreSQL lets multiple people query the same results
- **Small snapshots**: JSON + `jq` is fine for quick one-off checks

For snapshots with more than ~100K nodes, any database format is recommended over JSON.

## Database Compatibility Notes

The schema and views are designed to work across all three databases. Key dialect differences are handled automatically:

| Feature | SQLite | MySQL | PostgreSQL |
|---------|--------|-------|------------|
| Duplicate-safe insert | `INSERT OR IGNORE` | `INSERT IGNORE` | `INSERT ... ON CONFLICT DO NOTHING` |
| String concatenation | `\|\|` | `CONCAT()` | `\|\|` |
| Auto-increment PK | `INTEGER PRIMARY KEY` | `INTEGER PRIMARY KEY AUTO_INCREMENT` | `SERIAL PRIMARY KEY` |
| Bulk insert tuning | `PRAGMA journal_mode=OFF`, etc. | `SET unique_checks=0`, etc. | `SET synchronous_commit=off` |

> **Note**: The example queries in this document use SQLite syntax (`printf()`, `||`). When using MySQL, replace `||` with `CONCAT()` and `printf()` with `FORMAT()` or `CONCAT()` as appropriate. PostgreSQL uses `||` like SQLite but uses `format()` or `to_char()` instead of `printf()`.
