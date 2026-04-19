# rmem:serve — Persistent Query Server

## Status

Phase 1 (query-only fork + background SCC builder) and Phase 2
(ui.* IPC) are implemented. MCP server adapter is also implemented.

## Problem

`rmem:query` and `rmem:explore` both pay CSR substrate construction
cost on every invocation. For large rmem files (34M nodes, 60M edges),
this takes 10-30 seconds. When an AI assistant or human analyst needs
to run a series of exploratory queries, this per-invocation cost
makes interactive investigation impractical.

## Goal

A persistent process that loads the rmem once and serves queries
over a Unix domain socket. Both CLI tools and AI assistants can
connect, send a JSON query, receive a JSON response, and disconnect
— without paying the loading cost again.

## Architecture

The primary mode is **explore-integrated**: `rmem:explore --serve`
forks a query child after substrate construction. Parent runs an
async event loop (stream_select over tty + supervisor pipes),
serving as both TUI and orchestrator. Child serves query socket.

A standalone `rmem:serve` (headless) wraps the same query service
for CI / AI-only use cases.

```
┌──────────────────────────────────────────────────┐
│  rmem:explore --serve=/path/to/sock              │
│                                                  │
│  load substrate + model                          │
│  prewarm shared caches                           │
│  clear build-only cast caches                    │
│  fork()                                          │
│  ├─ parent: async event loop (tty + supervision) │
│  │   owns TUI state, handles ui.*, server.*      │
│  │   supervises query child + builder children   │
│  └─ child:  socket accept → query.* → respond    │
│             (read-only, never mutates TUI)        │
│                                                  │
│  Substrate shared via CoW (FFI + PHP arrays)     │
└──────────────────────────────────────────────────┘
        ↕ Unix domain socket
┌──────────────┐  ┌──────────────┐  ┌──────────────┐
│  rmem:query   │  │ AI assistant │  │  rmem:mcp    │
│  --server=... │  │ (Bash tool)  │  │ (MCP stdio)  │
└──────────────┘  └──────────────┘  └──────────────┘
```

## Protocol

### Transport

Unix domain socket (SOCK_STREAM). Path convention:
`$XDG_RUNTIME_DIR/reli/rmem-serve/<server-id>.sock` with 0700
directory permissions. Falls back to `/tmp/reli-rmem-<pid>.sock`
when `$XDG_RUNTIME_DIR` is not set.

### Socket ownership: parent router (single socket)

The parent owns the socket and routes requests by namespace:

```
client → parent socket
  server.*  → parent handles directly
  ui.*      → parent handles directly
  query.*   → parent forwards to query child via pipe
              query child responds via pipe
              parent relays response to client
```

This gives the cleanest UX and permission model:
- Client connects to one socket, sends any action
- `--serve-control` is enforced in the parent for `ui.*`
- `server.shutdown` is always available
- No client wrapper needed to manage two sockets

Tradeoff: query responses pass through parent. With response
limits and work budgets enforced, payloads stay small enough
(< 1 MB) for this to be practical.

### Action namespaces

- `query.*` — read-only data queries, forwarded to query child
- `ui.*` — TUI state read/write, handled by parent (requires `--serve-control`)
- `server.*` — lifecycle management, handled by parent

### Request format

```json
{"action": "server.hello"}
{"action": "server.ping"}
{"action": "server.shutdown"}

{"action": "query.roots"}
{"action": "query.children", "node_id": 123, "limit": 100}
{"action": "query.children", "node_id": 123, "all_edges": true, "sort": "link", "limit": 100}
{"action": "query.parents", "node_id": 123, "limit": 100}
{"action": "query.node_detail", "node_id": 123}
{"action": "query.sandwich", "node_id": 123, "limit": 100}
{"action": "query.path_to_root", "node_id": 123}
{"action": "query.class_ranking", "limit": 50}
{"action": "query.type_ranking", "limit": 50}
{"action": "query.top_retained", "limit": 50}
{"action": "query.nodes_by_class", "class": "App\\Models\\User", "limit": 100}
{"action": "query.nodes_by_type", "type": "ZendArray", "limit": 100}
{"action": "query.find_by_address", "address": "0x7f4a2c001230"}
{"action": "query.find_function_def", "name": "App\\Services\\Foo::bar"}
{"action": "query.find_class_def", "name": "App\\Models\\User"}
{"action": "query.subtree_stats", "node_id": 123, "max_nodes": 100000, "max_depth": 50}
{"action": "query.search", "pattern": "password", "limit": 100}
{"action": "query.scc_ranking", "limit": 50}
{"action": "query.scc_for_node", "node_id": 123}
```

### Response format

```json
{
  "ok": true,
  "data": [...],
  "total_count": 5000,
  "truncated": true,
  "limit": 100
}
{"ok": true, "data": {"type": "ObjectContext", "class": "User", ...}}
{"ok": false, "error": "Node not found: 999999"}
{"ok": false, "error_code": "not_ready", "error": "SCC data is still computing"}
```

All list responses include `total_count`, `truncated`, and `limit`
fields. Default limit is 100 for children/parents/rankings, 50 for
top_retained, configurable per-request.

Distinguish empty results from not-ready: `query.scc_ranking` returns
`error_code: "not_ready"` while computing, `data: []` when ready but
no cycles found.

### server.hello response

Returns file identity, protocol version, and derived data readiness:

```json
{
  "ok": true,
  "data": {
    "protocol_version": 1,
    "server_id": "a1b2c3",
    "rmem_path": "/path/to/output.rmem",
    "rmem_size": 1234567890,
    "node_count": 34000000,
    "edge_count": 60000000,
    "serve_control": true,
    "derived": {
      "generation": "mtime:size:hash",
      "scc_ready": false
    }
  }
}
```

### Composite actions

`query.sandwich` returns parents + node_detail + children in one
response:

```json
{
  "ok": true,
  "data": {
    "parents": [...],
    "detail": {"type": "...", "class": "...", "scc_status": "ready", ...},
    "children": [...]
  },
  "truncated": {"parents": false, "children": true}
}
```

`query.subtree_stats` returns type/class breakdown under a node.
Bounded by `max_nodes` and `max_depth` to limit both response size
and internal work:

```json
{
  "ok": true,
  "data": {
    "total_retained": 12345678,
    "node_count": 456,
    "scanned_count": 456,
    "type_breakdown": [{"type": "ZendArray", "count": 200, "total": 5000000}, ...],
    "class_breakdown": [{"class": "User", "count": 50, "total": 3000000}, ...],
    "truncated": false
  }
}
```

### Work budgets

Response `limit` bounds output size but not internal work. For
actions that scan large node sets, work budgets prevent CPU and
memory pressure in the query child:

| Action | Work budget strategy |
|--------|---------------------|
| children, parents | CSR slice, naturally bounded |
| top_retained | top-k heap (O(N) scan, O(limit) memory) |
| class_ranking, type_ranking | prewarm/cached, O(1) to serve |
| nodes_by_class, nodes_by_type | full scan, use `scanned_count` + `has_more` |
| subtree_stats | `max_nodes` + `max_depth` bound traversal |
| search | global scan with pattern matching |

For `nodes_by_class` with 34M nodes, an `exact_total=false` option
avoids full-scan counting: return `scanned_count` and `has_more`
instead of `total_count`.

Cached rankings (class, type) are cheap after prewarm. Uncached
rankings return `error_code: "not_ready"` rather than blocking.

## Implementation

### RmemQueryService

Not a thin adapter — a budget-aware service layer that enforces
work limits on behalf of the query child. TUI-oriented model methods
may materialize and sort entire node lists; the service layer must
not do that for serve.

```php
final class RmemQueryService
{
    public function __construct(private RmemModel $model) {}

    public function handle(array $request): array
    {
        return match ($request['action'] ?? '') {
            'server.hello' => $this->hello(),
            'server.ping'  => ['ok' => true, 'data' => 'pong'],
            'query.roots'  => $this->roots($request),
            'query.children' => $this->children($request),
            // ...
        };
    }
}
```

Budget strategies per action:

| Action | TUI model method | Service strategy |
|--------|-----------------|-----------------|
| top_retained | getTopRetained (full sort) | top-k min-heap, O(N) scan, O(limit) memory |
| nodes_by_class | getNodesByClass (full scan + sort) | budgeted scan, scanned_count/has_more |
| class/type_ranking | getClassRanking (cached) | serve from cache; if uncached, return not_ready |
| subtree_stats | (new) | bounded DFS with max_nodes/max_depth |
| search | (global scan) | pattern match across all nodes, budgeted |

### Substrate public API for derived reload

The query child needs to reload derived sections at request
boundaries. Required additions to FfiCsrGraphSubstrate:

```php
public function reloadDerivedCacheIfAvailable(string $rmemPath): bool
public function hasSccData(): bool
public function getDerivedGeneration(): ?string
```

`reloadDerivedCacheIfAvailable()` must only be called between
requests, never during query processing.

### Prewarm before fork

Shared caches should be populated before fork so both parent and
child benefit without CoW duplication. Prewarm is staged to control
memory usage on constrained machines:

```
--prewarm=basic (default)
  definition index (function_table, class_table link_name → node_id)
  Reader::clearCastCache()

--prewarm=detail
  + location info (addresses, refcounts, classes, string values)
  + class/type rankings (cached O(N) scan)

--prewarm=full
  + attributes (from attributes section)
  + address index for O(log N) find_by_address
```

`RmemModel::ensureLocationInfoLoaded()` currently loads location
info and attributes together. For staged prewarm, split into:
- `loadLocationBasics()` — addresses, refcounts, classes
- `loadStringValues()` — string values (can be large)
- `loadAttributes()` — arbitrary attributes

Lazy caches created after fork (e.g. subtree_stats, search results)
are worker-local. This is fine — they are transient and should be
bounded by work budgets.

### Process model

Parent runs an async event loop using `stream_select` over the tty
fd and supervisor pipes. This replaces the current blocking
`pollKey()`, enabling the parent to:

- Render TUI without blocking on input
- Detect child exits via `pcntl_waitpid(WNOHANG)` each loop tick
- Handle `ui.*` requests via pipe from query child (phase 2)
- Restart query child after builder completion

CPU-heavy work (graph traversal, ranking computation, SCC) must
never run in the parent — it blocks the event loop and freezes the
TUI. All heavy read-only operations are isolated in child processes.

```
parent (async event loop):
  open rmem
  build substrate/model (skipScc: true)
  prewarm shared indexes
  clear build-only cast caches
  optionally fork SCC builder child
  fork query child
  event loop:
    stream_select(tty_fd, child_pipes, ...)
    handle tty input → TUI dispatch
    handle child status → restart if needed
    handle ui.* pipe → TUI state mutation (phase 2)

query child:
  create unix socket (owner-only directory)
  write PID to <socket_path>.pid
  accept loop:
    check sidecar generation (between requests)
    if changed: reload derived cache, update capabilities
    read newline-delimited JSON
    route to RmemQueryService
    write JSON response
  handle SIGTERM → drain current request → unlink socket → exit

SCC builder child (optional):
  compute SCC using inherited substrate (CoW)
  write complete sidecar atomically
  exit
```

### Client integration

For CLI:

```bash
echo '{"action":"query.children","node_id":123}' | \
  nc -U $XDG_RUNTIME_DIR/reli/rmem-serve/abc123.sock

php reli rmem:query --server=$XDG_RUNTIME_DIR/reli/rmem-serve/abc123.sock \
  --node=123 --children
```

For AI assistants:

```bash
echo '{"action":"query.sandwich","node_id":123}' | \
  nc -U $XDG_RUNTIME_DIR/reli/rmem-serve/abc123.sock | jq .
```

## Memory: CoW analysis

### FFI C buffers

FFI CSR buffers are expected to share well under CoW as long as
workers treat them as read-only. The C-heap buffers allocated by
FFI::new() are shared at the physical page level after fork, and
read-only access does not trigger page faults.

### PHP arrays and CData wrappers

Both FFI CData wrappers and PHP arrays are refcounted zvals. CoW
copies occur on the page containing the refcount header, not the
bulk data. However, PHP array CoW behavior is less predictable
than FFI C buffers — zval, HashTable, and allocator metadata can
be dirtied by runtime operations beyond simple reads.

Memory assumptions should be validated with PSS / Private_Dirty
measurements, not RSS.

### Measurement

Validate CoW effectiveness using `/proc/<pid>/smaps_rollup`:

```
parent after load:              Pss, Private_Dirty
child immediately after fork:   Pss, Private_Dirty
child after query.node_detail:  Pss, Private_Dirty
child after class_ranking:      Pss, Private_Dirty
child after subtree_stats:      Pss, Private_Dirty
```

Use results to classify operations as "prewarm before fork" vs
"budgeted worker-local".

## Resource management

### Socket security

NDA-sensitive data flows through the socket. Socket path should be
in an owner-only directory:

```
$XDG_RUNTIME_DIR/reli/rmem-serve/<server-id>.sock  (0700 dir)
```

The server generates a random server_id, returns it in `server.hello`,
and optionally requires it as a token in requests.

### Socket lifecycle

- Server creates socket directory + socket on startup, unlinks both
  on shutdown
- `--timeout`: auto-shutdown after N seconds of inactivity (default: 3600)
- PID written to `<socket_path>.pid` for management
- `rmem:serve --status` or `rmem:serve --stop` for lifecycle control
- Parent monitors query child via `pcntl_waitpid(WNOHANG)` in
  event loop; restarts on unexpected exit

### Response limits

All list-returning actions enforce default limits:

| Action | Default limit | Configurable |
|--------|--------------|-------------|
| children, parents | 100 | yes |
| class_ranking, type_ranking | 50 | yes |
| top_retained | 50 | yes |
| nodes_by_class, nodes_by_type | 100 | yes |
| subtree_stats | max_nodes=100000, max_depth=50 | yes |
| search | 100 | yes |

Responses include `total_count` (or `scanned_count` + `has_more`)
and `truncated: true` when results are clipped.

## Background computation (phase 1.5)

### Problem

Some derived data is expensive to compute but not needed for basic
exploration:

| Data | Cost | Needed for |
|------|------|-----------|
| SCC (Tarjan + degree trimming) | 30-60s, ~400 MB FFI | cycle detection, retained accuracy |
| Future: sorted address index | seconds | find_by_address optimization |
| Future: inverted class index | seconds | fast nodes_by_class |

Note: canonical grouping is part of the initial load path in the
current loader and cannot be moved to background without a
`skipCanonical` minimal-load refactor.

### Architecture

```
load substrate (CSR + subtree sizes)   ← minimal, explore starts here
fork query child                       ← query.* ready immediately
fork SCC builder child                 ← background, heavy computation
  ├─ computeSccFfi() (using inherited CSR via CoW)
  ├─ writeDerivedCache() → .rmem.derived
  └─ exit
```

Phase 1.5 is limited to a **single SCC builder**. The current
`DerivedCacheWriter` writes the entire sidecar file atomically
(temp + rename). Multiple concurrent builders writing different
sections would clobber each other — the last rename wins. True
independent builders require a merge writer with file locking.

### Query child self-reload

The query child detects sidecar updates at request boundaries,
without parent involvement:

```
query child accept loop:
  before accepting next request:
    check sidecar mtime/size/generation
    if changed:
      reloadDerivedCacheIfAvailable()
      update scc_status capability flag
  handle next request
```

This avoids the need for the parent to manage query child restarts
or signal handling. The parent's event loop does not need to
participate in derived data lifecycle.

The alternative — parent-driven graceful restart of query child —
is viable when the parent has an async event loop (stream_select).
Both approaches are valid; self-reload is simpler for phase 1.5,
parent-driven restart is cleaner for phase 2 when the parent is
already an orchestrator.

### Progressive capability

Before SCC completes, queries return SCC-unaware results:

```json
{"action": "query.node_detail", "node_id": 123}
→ {"ok": true, "data": {..., "scc_id": null, "scc_status": "computing"}}

{"action": "query.scc_ranking"}
→ {"ok": false, "error_code": "not_ready", "error": "SCC data is still computing"}
```

After sidecar reload:

```json
{"action": "query.node_detail", "node_id": 123}
→ {"ok": true, "data": {..., "scc_id": 3, "scc_node_count": 12, "scc_status": "ready"}}
```

### SCC builder API

The builder should expose a single high-level API rather than
requiring callers to sequence individual private methods:

```php
// On FfiCsrGraphSubstrate:
public function computeAndWriteDerivedCache(string $rmemPath): bool
```

Internally handles dependency ordering:
1. Build canonical FFI mapping if not present
2. Compute subtree sizes if not present
3. Compute SCC
4. Atomic write sidecar

The query child's reload counterpart:

```php
public function reloadDerivedCacheIfAvailable(string $rmemPath): bool
```

Called only at request boundaries or child startup.

### Builder isolation

The SCC builder child has its own copy of the substrate (CoW shared,
but it will dirty pages during Tarjan — stack, visited set, etc.).
This dirty memory is freed when the child exits. The parent and
query child never see the builder's working memory.

The builder's only output is the sidecar file. It does not
communicate results via pipe or shared memory. This makes the
design robust against builder crashes — the worst case is no SCC
data, which is the same as the current skipScc mode.

## UI navigation (phase 2) — Implemented

### Design

Phase 2 adds `ui.*` actions behind `--serve-control` opt-in:

```json
{"action": "ui.navigate_sandwich", "node_id": 12345}
{"action": "ui.navigate_roots"}
{"action": "ui.navigate_back"}
{"action": "ui.navigate_top_retained"}
{"action": "ui.navigate_class_ranking"}
{"action": "ui.navigate_type_ranking"}
{"action": "ui.get_current_focus"}
{"action": "ui.get_current_selection"}
```

With the async parent event loop already in place, `ui.*` requests
arrive via pipe from query child (or via a control socket owned by
parent). The parent applies state mutations and renders on the next
tick.

`ui.*` responses include a `ui_revision` counter. Navigation
requests can include `if_revision` to reject stale navigations
(e.g. AI sending a navigate based on an outdated get_current_focus).

### Guided investigation workflow

```
Human: "Trace where this memory comes from"
AI:    query.roots → find largest branch
AI:    ui.navigate_sandwich call_frames
AI:    "call_frames is 1.5 GB, the largest branch. Looking at frame #8."
AI:    ui.navigate_sandwich frame_node
AI:    "$result in this frame holds 500 MB — it's an array of Users."
Human: "Show me more detail there"
AI:    ui.navigate_sandwich result_node
AI:    query.subtree_stats result_node
AI:    "3,000 User instances, 2.8 KB each. Eloquent models."
```

### State query (AI ← human screen)

AI can read what the human is looking at:

```json
{"action": "ui.get_current_focus"}
→ {"ok": true, "data": {"node_id": 456, "label": "...", "mode": "sandwich"}, "ui_revision": 42}
```

## Alternatives considered

### HTTP server

Heavier than needed. Unix socket is simpler and avoids port management.

### stdin/stdout line mode

Cannot be shared between explore and AI, and background process
stdin management is awkward.

### MCP (Model Context Protocol) server

Vendor-specific. Socket server is more universal — MCP adapter wraps
it (see `inspector:rmem:mcp` below).

## Implementation order

### Phase 1: query-only fork + async parent — Implemented

1. Async TUI event loop: replace blocking `pollKey()` with
   `stream_select` over tty fd
2. `RmemQueryService` — extract model-to-protocol conversion
3. `query.*` minimal set: `server.hello`, `query.node_detail`,
   `query.path_to_root`, `query.children`, `query.parents`,
   `query.sandwich`
4. `rmem:explore --serve` — parent event loop, fork query child
5. Response limits: `limit`, `truncated`, `total_count`
6. Work budgets: top-k heap for rankings, `scanned_count`/`has_more`
7. Socket security: `$XDG_RUNTIME_DIR`, owner-only dir, server_id
8. Additional queries: `find_by_address`, definition lookup,
   class/type/top_retained ranking, `subtree_stats`
9. Standalone `rmem:serve` — headless wrapper around RmemQueryService
10. PSS / Private_Dirty measurement to validate CoW assumptions

### Phase 1.5: background SCC builder — Implemented

11. SCC builder: fork child, compute, write sidecar, exit
12. Query child self-reload: check sidecar generation between requests
13. Substrate public API: `reloadDerivedCacheIfAvailable()`, `hasSccData()`,
    `getDerivedGeneration()`
14. `scc_status` / `error_code: "not_ready"` in protocol responses
15. `query.scc_ranking` action
16. Parent-driven query child restart as optional upgrade

### Phase 2: UI navigation — Implemented

17. `ui.*` IPC: pipe between query child and parent
18. `ui.navigate_sandwich`, `ui.navigate_roots`, `ui.navigate_back`,
    `ui.navigate_top_retained`, `ui.navigate_class_ranking`,
    `ui.navigate_type_ranking`, `ui.get_current_focus`,
    `ui.get_current_selection`
19. `ui_revision` for stale navigation rejection
20. `--serve-control` opt-in for `ui.*` actions

### MCP adapter — Implemented

21. `inspector:rmem:mcp` — stdio JSON-RPC server implementing the
    Model Context Protocol. Connects to the query server via
    `--rmem` (direct file load) or `--socket` (existing serve
    instance). Pass `--control` to enable `ui.*` tools.

### Future

- Sidecar merge writer for multiple independent builders
- `skipCanonical` minimal-load mode for faster explore startup
- Sorted address index / inverted class index as builder outputs

## Bonus: activity indicators in TUI

The async parent event loop enables free activity indicators.
Since the parent is not blocked on `pollKey()`, it can update the
TUI header/status bar on every tick (stream_select with ~100ms
timeout):

```
┌─────────────────────────────────────────────────────┐
│ rmem:explore [list all-edges]          ⣾ SCC...    │
│ ...                                                 │
│ ↑↓:select  Enter:drill  ...   ⠹ query processing  │
└─────────────────────────────────────────────────────┘
```

- **SCC builder running**: Braille spinner (`⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏`) in
  header while builder child is alive (detected via
  `pcntl_waitpid(WNOHANG)` each tick). Disappears on exit.
- **Query in progress**: spinner in status bar while query child
  is processing an AI request.
- **Sidecar reload**: brief flash `✓ SCC ready` after query child
  reloads derived cache.

Query child sends status events to parent via a status pipe:

```
query child → parent status pipe:
  query_start  {request_id, action}
  query_done   {request_id, action, ok, elapsed_ms}
  derived_reloaded {generation}
```

Builder child status is simpler: alive/dead via `pcntl_waitpid`.

Implementation cost: one `$spinner_frame = ($spinner_frame + 1) % 10`
per render cycle, a few characters in the header/status line.
