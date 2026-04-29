# rmem Integration Surface

Index of the wire surfaces that the `rmem:*` commands expose for
external clients: HTTP/SSE for browsers, Unix-socket JSON for headless
query servers, MCP tool surface for AI agents, and the focus bus that
ties all three together.

> **Audience and stability.** This document is integration-facing — a
> reference for code that talks to a running `rmem:live` / `rmem:serve` /
> `rmem:mcp` process from the outside. It lives under `internals/` for
> filing convenience, but is not a private implementation note: this
> page is the source of truth for external clients. Wire details may
> still change before 1.0; breaking changes will be called out in
> release notes.

For end-user usage of the commands themselves see
[../memory/rmem-explore-and-serve.md](../memory/rmem-explore-and-serve.md).
The full JSON-over-Unix-socket request/response specification lives in
[rmem-serve-design.md](rmem-serve-design.md); this page indexes the
action surface and points there for shapes.

## HTTP / SSE bridge

Started by `rmem:live` (standalone) or by `rmem:explore --http-bridge PORT`
(forked child of the TUI). Both expose the same surface.

### Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| `GET`  | `/` | viz HTML (gzip when the client supports it) |
| `GET`  | `/api/events` | Server-Sent Events stream |
| `POST` | `/api/navigate` | Body `{"node_id": int}` → broadcasts a `focus_node` SSE event to every subscriber |
| `POST` | `/api/highlight_set` | Body `{"node_ids": [int, …]}` → broadcasts a `highlight_set` SSE event |

### SSE payloads

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

## Unix-socket query protocol

Started by `rmem:serve`. Newline-delimited JSON over a Unix domain
socket. Full protocol specification is in
[rmem-serve-design.md](rmem-serve-design.md).

### Actions

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

## MCP tools

Exposed by `rmem:mcp`. Tool availability depends on how the server
was launched.

**Query tools** (always available):
`rmem_roots`, `rmem_children`, `rmem_parents`, `rmem_sandwich`,
`rmem_node_detail`, `rmem_path_to_root`, `rmem_class_ranking`,
`rmem_type_ranking`, `rmem_top_retained`, `rmem_search`,
`rmem_scc_ranking`, `rmem_scc_for_node`, `rmem_subtree_stats`,
`rmem_nodes_by_class`, `rmem_nodes_by_type`,
`rmem_find_by_address`, `rmem_find_function_def`,
`rmem_find_class_def`, `rmem_hello`

**UI control tools** (with `--control`, requires
`rmem:explore --serve --serve-control`):
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

The server ships with built-in instructions covering PHP memory
concepts (shallow vs retained size, tree edges, SCCs, etc.) and
recommended investigation workflows, so MCP-capable clients can
usually start with useful built-in guidance.

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
