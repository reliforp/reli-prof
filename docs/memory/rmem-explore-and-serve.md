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

The TUI, browser, and AI surfaces can be wired together over a single
focus bus so that moving the cursor anywhere is reflected everywhere
— see [internals/rmem-protocol.md](../internals/rmem-protocol.md#three-way-focus-bus).

## rmem:explore

Interactive terminal UI for browsing a memory snapshot.

```bash
reli rmem:explore output.rmem
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
event to the HTTP bridge (see [§ rmem:live](#rmemlive) and the
[focus bus diagram](../internals/rmem-protocol.md#three-way-focus-bus)).
Browsers highlight the node the cursor is on; AI agents connected
via `rmem:mcp --bridge` see the same focus. A green `follow` badge
appears in the footer while the mode is active. Toggle again with
`f` to return to manual-drill-only.

### Options

```bash
# Start focused on a specific node (from report findings)
reli rmem:explore output.rmem --node=12345

# Start focused on a memory address
reli rmem:explore output.rmem --address=0x7f1234567890

# Set memory limit
reli rmem:explore output.rmem --memory-limit=4G

# Fork a query server for AI assistant access
reli rmem:explore output.rmem --serve

# Enable TUI control via the query server
reli rmem:explore output.rmem --serve --serve-control

# Fork an HTTP/SSE bridge so browsers follow this TUI live.
# Combine with --serve to expose both the Unix socket and HTTP.
reli rmem:explore output.rmem --http-bridge 8080
# Then press `f` inside the TUI and open http://127.0.0.1:8080/

# Rewrite file paths recorded in the snapshot for local navigation.
# Useful when the snapshot was captured inside a container or on a
# remote host and you want the "source:" lines in the detail pane
# to point at your local checkout. May be repeated; longest prefix
# wins.
reli rmem:explore output.rmem \
    --path-map /var/www/html=/home/me/project
```

### Source locations

The right-hand detail pane surfaces up to three source-location
lines per node, depending on what is available:

- **`source:`** — the node itself carries a `filename` attribute.
  Emitted for `CallFrameContext`, `OpArrayContext`, and user-defined
  `ClassEntryContext` nodes.
- **`defined:`** — for object zvals, the filename of the class the
  object belongs to. Resolved by hopping through the class name to
  the matching `class_entry` node.
- **`held by:`** — the filename of the nearest tree-ancestor that
  has a source location, so bare arrays / scalars whose definition
  site is implicit still get a navigation hint. Only emitted when
  the node has no `source:` of its own.

`line_start`–`line_end` is printed for nodes that carry a range
(op_array, class_entry); single-line nodes (`lineno`) print as
`file:line`. `--path-map` is applied to every output line, so
values recorded as `/var/www/html/src/App.php` become
`/home/me/project/src/App.php` in the pane.

The paths show up in a form terminals such as iTerm2, VS Code's
integrated terminal, and Kitty recognise as clickable — `Cmd`/`Ctrl`-click
(or the terminal's keyboard equivalent) opens the file at the
given line in your editor.

The first (highest-priority) location is also surfaced as
`source_location` in `query.node_detail` responses, and the full
list as `source_locations[]`, so MCP/HTTP bridge clients can turn
them into `vscode://file/…` or `https://github.com/…` links at
render time.

### HTTP bridge options (`--http-bridge`)

When `--http-bridge PORT` is set, a child process is forked that
serves the browser viz at `/` and mirrors TUI focus to it. Wire-level
endpoints are documented in
[internals/rmem-protocol.md](../internals/rmem-protocol.md#http--sse-bridge).

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
reli rmem:explore output.rmem --node=113629
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
reli rmem:viz output.rmem
# wrote output.rmem.viz.html

# Inspect a larger subgraph / include reference edges
reli rmem:viz output.rmem --top 1000 --depth 2 --all-edges

# Send somewhere specific
reli rmem:viz output.rmem -o /tmp/viz.html
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

> **Network access required when opening the HTML.** The generated
> `<file>.viz.html` references the libraries above by `https://unpkg.com/...`
> URLs. On an air-gapped host or a browser without internet access
> the page renders an empty canvas (no errors, just blank panels).
> If you need offline viewing, mirror the three scripts to a local
> path and rewrite the `<script src="...">` tags in the emitted
> HTML, or run `rmem:live` instead and serve over a private network
> from a host that can reach unpkg.

## rmem:live

Same viz as `rmem:viz`, but served over HTTP with a **live SSE event
channel** so browsers stay in sync with each other and with any TUI
/ MCP client that joins the bus.

```bash
reli rmem:live output.rmem
# listening on tcp://127.0.0.1:8080 — open http://127.0.0.1:8080/ in a browser

# Larger subgraph, wider-open network
reli rmem:live output.rmem --port 18080 --host 0.0.0.0 --top 2000
```

### Options

Same subgraph knobs as `rmem:viz` (`--top`, `--depth`, `--all-edges`,
`--max-nodes`, `--max-children`) plus:

| Option | Default | Description |
|--------|---------|-------------|
| `--host` | `127.0.0.1` | Bind host (use `0.0.0.0` or `::` for LAN access) |
| `--port N` / `-p` | `8080` | TCP port |

### Wire protocol

HTTP endpoints (`/api/events`, `/api/navigate`, `/api/highlight_set`)
and SSE payload shapes are documented in
[internals/rmem-protocol.md](../internals/rmem-protocol.md#http--sse-bridge).

## rmem:serve

Persistent headless query server. Loads the `.rmem` file once and
serves queries over a Unix domain socket.

```bash
reli rmem:serve output.rmem
# serving on /run/user/1000/reli/rmem-serve/a1b2c3d4.sock

# With custom socket path
reli rmem:serve output.rmem --socket=/tmp/my-rmem.sock

# Auto-shutdown after 30 minutes of inactivity
reli rmem:serve output.rmem --timeout=1800
```

### Querying

```bash
# Using rmem:query CLI (--server takes the bare socket path)
reli rmem:query --server=/run/user/.../a1b2c3d4.sock --node=12345

# Using socat
echo '{"action":"query.roots"}' | socat - UNIX-CONNECT:/run/user/.../a1b2c3d4.sock

# Raw JSON action
echo '{"action":"query.sandwich","node_id":12345}' | socat - UNIX-CONNECT:/path/to/sock
```

### Protocol

Newline-delimited JSON over Unix socket. The action list and full
specification are in
[internals/rmem-protocol.md](../internals/rmem-protocol.md#unix-socket-query-protocol)
and [internals/rmem-serve-design.md](../internals/rmem-serve-design.md).

## rmem:mcp

MCP (Model Context Protocol) server for AI-assisted memory analysis.
Any MCP-capable AI agent (Claude Code, Cursor, etc.) can discover and
use the tools automatically.

### Setup

```bash
# Headless mode: loads .rmem in-process
reli rmem:mcp --rmem=output.rmem

# Connect to existing server
reli rmem:mcp --socket=/path/to/sock

# With TUI control (requires explore --serve --serve-control)
reli rmem:mcp --socket=/path/to/sock --control

# With HTTP bridge (so AI navigation lights up every connected browser)
reli rmem:mcp --rmem=output.rmem --bridge=http://127.0.0.1:8080
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

Three categories — query (always available), UI control (with
`--control`), and browser bridge (with `--bridge URL`). The full
tool list is in
[internals/rmem-protocol.md](../internals/rmem-protocol.md#mcp-tools).

The server includes built-in instructions that teach the AI agent
about PHP memory concepts (shallow vs retained size, tree edges,
SCCs, etc.) and recommended investigation workflows, so no
additional documentation is needed for the agent to use the tools
effectively.

## Three-way focus bus

`rmem:explore --http-bridge` plus `rmem:mcp --bridge URL` pointed at
the same port create a single live bus where TUI cursor moves,
browser clicks, and AI tool calls all reflect everywhere. Architecture
diagram and event flow:
[internals/rmem-protocol.md § Three-way focus bus](../internals/rmem-protocol.md#three-way-focus-bus).
