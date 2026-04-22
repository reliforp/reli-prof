# rmem:explore, rmem:viz, rmem:live, rmem:serve, rmem:mcp

Interactive tools for browsing `.rmem` memory snapshots.

## Overview

After capturing a memory dump and running `inspector:memory:analyze` to produce
a `.rmem` file, these commands let you explore the memory graph
interactively:

| Command | Purpose |
|---------|---------|
| `rmem:explore` | Interactive TUI for browsing nodes, rankings, and cycles |
| `rmem:viz` | Emit a standalone HTML visualization (Circle Pack / Treemap / Sunburst / 3D Force) |
| `rmem:live` | Serve the viz over HTTP with a live SSE focus bus |
| `rmem:serve` | Persistent query server over Unix socket |
| `rmem:mcp` | MCP (Model Context Protocol) server for AI assistants |
| `rmem:query` | One-shot CLI queries against a `.rmem` file or running server |

All five UI surfaces (TUI, browser, AI) can be wired together over a
single focus bus so that moving the cursor anywhere is reflected
everywhere — see [§ Three-way focus bus](#three-way-focus-bus).

## rmem:explore

Interactive terminal UI for browsing a memory snapshot.

```bash
php reli rmem:explore output.rmem
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
| `f` | Toggle **follow mode** — cursor moves broadcast as focus events (see below) |
| `m` | Bookmark selected node |
| `'` | Show bookmarks |
| `?` | Help overlay |
| `q` | Quit |

### Mouse

The TUI accepts mouse input via SGR tracking:

| Action | Effect |
|--------|--------|
| Left-click on a list / sandwich row | Move selection cursor to that row |
| Double-click on the same row (within 400 ms) | Enter (drill into sandwich view) |
| Scroll wheel up / down | Scroll the pane under the pointer |
| Left-click on a sidebar "Path to root" step | Jump sandwich view to that ancestor |

Sidebar path steps are rendered underlined cyan to cue clickability.
Walking *up* past the top of the tree lands on a synthetic `(roots)`
entry whose children are every forest branch (class_table /
call_frames / objects_store / …) — useful for hopping sideways
between top-level branches without returning to the roots list.

### Follow mode (`f`)

When follow mode is on, every cursor move in the TUI — arrow keys,
mouse click, `Tab` between panes, scroll wheel — emits a focus_node
event to the HTTP bridge (see [§ rmem:live](#rmemlive) and
[§ Three-way focus bus](#three-way-focus-bus)). Browsers highlight
the node the cursor is on; AI agents connected via `rmem:mcp
--bridge` see the same focus. A green `follow` badge appears in the
footer while the mode is active. Toggle again with `f` to return
to manual-drill-only.

### Options

```bash
# Start focused on a specific node (from report findings)
php reli rmem:explore output.rmem --node=12345

# Start focused on a memory address
php reli rmem:explore output.rmem --address=0x7f1234567890

# Set memory limit
php reli rmem:explore output.rmem --memory-limit=4G

# Fork a query server for AI assistant access
php reli rmem:explore output.rmem --serve

# Enable TUI control via the query server
php reli rmem:explore output.rmem --serve --serve-control

# Fork an HTTP/SSE bridge so browsers follow this TUI live.
# Combine with --serve to expose both the Unix socket and HTTP.
php reli rmem:explore output.rmem --http-bridge 8080
# Then press `f` inside the TUI and open http://127.0.0.1:8080/
```

### HTTP bridge options (`--http-bridge`)

When `--http-bridge PORT` is set, a child process is forked that
serves the browser viz at `/` and an SSE focus stream at
`/api/events`. The bridge mirrors TUI focus changes out, and
browser / MCP `POST /api/navigate` back into the TUI.

| Option | Default | Description |
|--------|---------|-------------|
| `--http-host` | `127.0.0.1` | Bind host (use `0.0.0.0` for all IPv4, `::` for dual-stack) |
| `--http-top` | `500` | Top-retained seeds to include in the browser subgraph |
| `--http-depth` | `1` | Downward expansion depth from each seed (meaningful hops only; structural wrappers are free) |
| `--http-all-edges` | off | Include non-tree reference edges in the 3D Force view |
| `--http-max-nodes` | `1000` | Hard cap on total nodes in the extracted subgraph |
| `--http-max-children` | `100` | Per-parent cap on BFS fan-out (top-K by retained + stride sample) |

### Report integration

The `inspector:memory:report` text output includes node IDs (`#N`) in findings,
arrays, and strings tables. Use `--node=N` to jump directly to a
finding in explore:

```
=== Findings ===

  [HIGH] 31.7 MB impacted
    bottleneck_path: call_frames → collectAll → $sink (31.7 MB)
    Explore: rmem:explore --node=113629  (#113629, #113604)
```

```bash
php reli rmem:explore output.rmem --node=113629
```

## rmem:viz

Emit a **standalone HTML file** that visualises the snapshot in four
views backed by d3 and 3d-force-graph, loaded from a CDN at runtime:

| View | Best for |
|------|----------|
| **Circle Pack** | Hierarchical proportions — "what retains what, how big" |
| **Treemap** | Same information as a space-filling rectangle grid |
| **Sunburst** | Radial view of the ownership chain |
| **3D Force** | Reference graph / cluster layout in WebGL |

```bash
php reli rmem:viz output.rmem
# wrote output.rmem.viz.html

# Inspect a larger subgraph / include reference edges
php reli rmem:viz output.rmem --top 1000 --depth 2 --all-edges

# Send somewhere specific
php reli rmem:viz output.rmem -o /tmp/viz.html
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--top N` / `-t` | `500` | Seed count: top-N retained nodes |
| `--depth N` / `-d` | `1` | Downward BFS depth (structural wrappers are free hops) |
| `--all-edges` | off | Include non-tree reference edges in 3D Force |
| `--max-nodes N` | `1000` | Hard cap on total nodes in the subgraph |
| `--max-children N` | `100` | Per-parent fan-out cap (top-K retained + stride sample) |
| `--output PATH` / `-o` | `<file>.viz.html` | Output HTML path |
| `--memory-limit` | | PHP memory_limit override for the generator |

### Interactive controls (in the browser)

- **Tabs**: switch between Circle Pack / Treemap / Sunburst / 3D Force
- **Theme picker**: Midnight / Pure Black / Slate / Daylight
- **Color grouping**: by class / by forest root / by type
- **Palette**: 23 schemes (Tableau 10, Category 10, Set1/2/3, Sinebow,
  Spectral, Turbo, Viridis, Plasma, …) split across Categorical /
  Cyclic / Sequential / Diverging optgroups
- **Pack zoom**: opt-in click-to-zoom into a circle (right-click or
  background-click to zoom back out)
- **Detail panel**: every *Path to root* step is a clickable chip;
  clicking jumps every connected view there

All preferences persist per browser via `localStorage`.

### Browser-side dependencies

Loaded at runtime from the public unpkg CDN — nothing is bundled in
the HTML itself:

| Library | Version | License |
|---------|---------|---------|
| d3 | v7 | ISC |
| three.js | 0.150.1 | MIT |
| 3d-force-graph | 1.73.0 | MIT |

## rmem:live

Same viz as `rmem:viz`, but served over HTTP with a **live SSE event
channel** so browsers stay in sync with each other and with any TUI
/ MCP client that joins the bus.

```bash
php reli rmem:live output.rmem
# listening on tcp://127.0.0.1:8080 — open http://127.0.0.1:8080/ in a browser

# Larger subgraph, wider-open network
php reli rmem:live output.rmem --port 18080 --host 0.0.0.0 --top 2000
```

### Options

Same subgraph knobs as `rmem:viz` (`--top`, `--depth`, `--all-edges`,
`--max-nodes`, `--max-children`) plus:

| Option | Default | Description |
|--------|---------|-------------|
| `--host` | `127.0.0.1` | Bind host (use `0.0.0.0` or `::` for LAN access) |
| `--port N` / `-p` | `8080` | TCP port |

### HTTP endpoints

| Method | Path | Purpose |
|--------|------|---------|
| `GET`  | `/` | viz HTML (gzip when the client supports it) |
| `GET`  | `/api/events` | Server-Sent Events stream |
| `POST` | `/api/navigate` | Body `{"node_id": int}` → broadcasts a `focus_node` SSE event to every subscriber |
| `POST` | `/api/highlight_set` | Body `{"node_ids": [int, …]}` → broadcasts a `highlight_set` SSE event |

SSE payloads:

```
event: focus_node
data: {"node_id": 1234, "path_node_ids": [0, 4, 5, 6, 7], "label": "StringContext = \"book\""}

event: highlight_set
data: {"node_ids": [100, 200, 300]}
```

Browsers handle `focus_node` by highlighting the target (or the
nearest visible ancestor if the node isn't in the induced subgraph
— `path_node_ids` drives the fallback); `highlight_set` dims
non-members in 3D Force and rings each member in the SVG views.

## rmem:serve

Persistent headless query server. Loads the `.rmem` file once and
serves queries over a Unix domain socket.

```bash
php reli rmem:serve output.rmem
# serving on /run/user/1000/reli/rmem-serve/a1b2c3d4.sock

# With custom socket path
php reli rmem:serve output.rmem --socket=/tmp/my-rmem.sock

# Auto-shutdown after 30 minutes of inactivity
php reli rmem:serve output.rmem --timeout=1800
```

### Querying

```bash
# Using rmem:query CLI
php reli rmem:query --server=sock:/run/user/.../a1b2c3d4.sock

# Using socat
echo '{"action":"query.roots"}' | socat - UNIX-CONNECT:/run/user/.../a1b2c3d4.sock

# Raw JSON action
echo '{"action":"query.sandwich","node_id":12345}' | socat - UNIX-CONNECT:/path/to/sock
```

### Protocol

Newline-delimited JSON over Unix socket. See
[rmem-serve-design.md](../internals/rmem-serve-design.md) for the full
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
php reli rmem:mcp --rmem=output.rmem

# Connect to existing server
php reli rmem:mcp --socket=/path/to/sock

# With TUI control (requires explore --serve --serve-control)
php reli rmem:mcp --socket=/path/to/sock --control

# With HTTP bridge (so AI navigation lights up every connected browser)
php reli rmem:mcp --rmem=output.rmem --bridge=http://127.0.0.1:8080
```

### Claude Code integration

Add to `.mcp.json` in the project root:

```json
{
  "mcpServers": {
    "reli-rmem": {
      "command": "php",
      "args": ["/path/to/reli", "rmem:mcp", "--rmem=output.rmem"]
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
        "/path/to/reli", "rmem:mcp",
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

**Browser bridge tools** (with `--bridge URL`):
- `rmem_broadcast_focus` — POST `/api/navigate` to highlight a node
  across every connected browser (and the TUI, when it is the
  origin of the bridge).
- `rmem_highlight_set` — POST `/api/highlight_set` to light up a
  *set* of nodes together (useful for "these N are part of the
  leak"); empty array clears.

The server includes built-in instructions that teach the AI agent
about PHP memory concepts (shallow vs retained size, tree edges,
SCCs, etc.) and recommended investigation workflows, so no
additional documentation is needed for the agent to use the tools
effectively.

## Three-way focus bus

`rmem:explore --http-bridge` plus `rmem:mcp --bridge URL` pointed at
the same port create a single live bus that every surface reads
from and writes to:

```
             focus pipe          SSE                 POST /api/navigate
    ┌──────────────────────▶ bridge child ────▶ Browsers (any tab) ─────────┐
    │                        (HTTP/SSE)                                     │
    │                              ▲                                        │
    │                              │ POST /api/navigate  ┌──────────────────┘
    │                              │                     │
    │                              │                 MCP ──▶ AI agents
    │                              │                 (--bridge URL)
    │                              │
    │              navigate pipe (child → TUI)
    TUI ◀─────────────────────────┘
    (rmem:explore --http-bridge)
```

- TUI cursor with **follow mode on** (`f`) → every browser re-rings
  the focused node and the AI can query it by id.
- **Browser click or Pack-zoom** → `POST /api/navigate` →
  re-broadcast as SSE to every other browser **and** handed to the
  TUI over the reverse pipe so the sandwich view jumps there.
- **AI** calls `rmem_broadcast_focus(node_id)` / `rmem_highlight_set(ids)`
  via MCP → browsers plus the TUI react instantly.

Out-of-subgraph focus events carry the full ancestor chain
(`path_node_ids`); the browser falls back to the nearest visible
ancestor and surfaces a breadcrumb in the detail panel so the user
never gets a blank highlight.
