# rmem:explore, rmem:serve, rmem:mcp

Interactive tools for browsing `.rmem` memory snapshots.

## Overview

After capturing a memory dump and running `memory:analyze` to produce
a `.rmem` file, these commands let you explore the memory graph
interactively:

| Command | Purpose |
|---------|---------|
| `rmem:explore` | Interactive TUI for browsing nodes, rankings, and cycles |
| `rmem:serve` | Persistent query server over Unix socket |
| `rmem:mcp` | MCP (Model Context Protocol) server for AI assistants |
| `rmem:query` | One-shot CLI queries against a `.rmem` file or running server |

## rmem:explore

Interactive terminal UI for browsing a memory snapshot.

```bash
php reli inspector:rmem:explore output.rmem
```

### Views

| Key | View | Description |
|-----|------|-------------|
| `t` | Roots | Top-level branches (call_frames, class_table, etc.) |
| `s` | Top retained | Nodes ranked by retained size |
| `c` | Class ranking | PHP classes ranked by total memory |
| `y` | Type ranking | Node types ranked by total memory |
| `x` | Cycles | Strongly connected components (reference cycles) |
| `i` | Subtree info | Type/class breakdown of a node's subtree |

### Navigation

| Key | Action |
|-----|--------|
| `Enter` | Open sandwich view (parents + detail + children) |
| `Backspace` | Go back |
| `Tab` | Switch pane in sandwich view |
| `/` | Filter current list |
| `F` | Global search (labels, classes, string values) |
| `a` | Jump to address (`0x...`) or node ID (`#N`) |
| `g` | Go to function/class definition |
| `r` | Toggle sort: retained size / link name |
| `n` | Toggle tree edges / all edges |
| `o` | Toggle sidebar (node detail + path to root) |
| `m` | Bookmark selected node |
| `'` | Show bookmarks |
| `?` | Help overlay |
| `q` | Quit |

### Options

```bash
# Start focused on a specific node (from report findings)
php reli inspector:rmem:explore output.rmem --node=12345

# Start focused on a memory address
php reli inspector:rmem:explore output.rmem --address=0x7f1234567890

# Set memory limit
php reli inspector:rmem:explore output.rmem --memory-limit=4G

# Fork a query server for AI assistant access
php reli inspector:rmem:explore output.rmem --serve

# Enable TUI control via the query server
php reli inspector:rmem:explore output.rmem --serve --serve-control
```

### Report integration

The `memory:report` text output includes node IDs (`#N`) in findings,
arrays, and strings tables. Use `--node=N` to jump directly to a
finding in explore:

```
=== Findings ===

  [HIGH] 31.7 MB impacted
    bottleneck_path: call_frames → collectAll → $sink (31.7 MB)
    Explore: inspector:rmem:explore --node=113629  (#113629, #113604)
```

```bash
php reli inspector:rmem:explore output.rmem --node=113629
```

## rmem:serve

Persistent headless query server. Loads the `.rmem` file once and
serves queries over a Unix domain socket.

```bash
php reli inspector:rmem:serve output.rmem
# serving on /run/user/1000/reli/rmem-serve/a1b2c3d4.sock

# With custom socket path
php reli inspector:rmem:serve output.rmem --socket=/tmp/my-rmem.sock

# Auto-shutdown after 30 minutes of inactivity
php reli inspector:rmem:serve output.rmem --timeout=1800
```

### Querying

```bash
# Using rmem:query CLI
php reli inspector:rmem:query --server=sock:/run/user/.../a1b2c3d4.sock

# Using socat
echo '{"action":"query.roots"}' | socat - UNIX-CONNECT:/run/user/.../a1b2c3d4.sock

# Raw JSON action
echo '{"action":"query.sandwich","node_id":12345}' | socat - UNIX-CONNECT:/path/to/sock
```

### Protocol

Newline-delimited JSON over Unix socket. See
[rmem-serve-design.md](internals/rmem-serve-design.md) for the full
protocol specification.

Key actions:

| Action | Description |
|--------|-------------|
| `server.hello` | Server info, node/edge counts |
| `query.roots` | Root branches |
| `query.children` | Children of a node (sorted by retained) |
| `query.parents` | Parents that retain a node |
| `query.sandwich` | Parents + detail + children in one call |
| `query.node_detail` | Full node detail |
| `query.path_to_root` | Ownership chain to root |
| `query.class_ranking` | Classes by memory |
| `query.type_ranking` | Node types by memory |
| `query.top_retained` | Largest retained nodes |
| `query.search` | Global search across labels, classes, strings |
| `query.scc_ranking` | Reference cycles by size |
| `query.scc_for_node` | Cycle detail for a node |
| `query.subtree_stats` | Type/class breakdown of a subtree |

## rmem:mcp

MCP (Model Context Protocol) server for AI-assisted memory analysis.
Any MCP-capable AI agent (Claude Code, Cursor, etc.) can discover and
use the tools automatically.

### Setup

```bash
# Headless mode: loads .rmem in-process
php reli inspector:rmem:mcp --rmem=output.rmem

# Connect to existing server
php reli inspector:rmem:mcp --socket=/path/to/sock

# With TUI control (requires explore --serve --serve-control)
php reli inspector:rmem:mcp --socket=/path/to/sock --control
```

### Claude Code integration

Add to `.mcp.json` in the project root:

```json
{
  "mcpServers": {
    "reli-rmem": {
      "command": "php",
      "args": ["/path/to/reli", "inspector:rmem:mcp", "--rmem=output.rmem"]
    }
  }
}
```

For co-pilot mode (AI + human sharing TUI):

```json
{
  "mcpServers": {
    "reli-rmem": {
      "command": "php",
      "args": [
        "/path/to/reli", "inspector:rmem:mcp",
        "--socket=/run/user/1000/reli/rmem-serve/xxx.sock",
        "--control"
      ]
    }
  }
}
```

### Tools provided

**Query tools** (always available):
`rmem_roots`, `rmem_children`, `rmem_parents`, `rmem_sandwich`,
`rmem_node_detail`, `rmem_path_to_root`, `rmem_class_ranking`,
`rmem_type_ranking`, `rmem_top_retained`, `rmem_search`,
`rmem_scc_ranking`, `rmem_scc_for_node`, `rmem_subtree_stats`,
`rmem_nodes_by_class`, `rmem_nodes_by_type`,
`rmem_find_by_address`, `rmem_find_function_def`,
`rmem_find_class_def`, `rmem_hello`

**UI control tools** (with `--control`):
`rmem_navigate`, `rmem_navigate_roots`, `rmem_navigate_back`,
`rmem_navigate_class_ranking`, `rmem_navigate_type_ranking`,
`rmem_navigate_top_retained`, `rmem_get_focus`, `rmem_get_selection`

The server includes built-in instructions that teach the AI agent
about PHP memory concepts (shallow vs retained size, tree edges,
SCCs, etc.) and recommended investigation workflows, so no
additional documentation is needed for the agent to use the tools
effectively.
