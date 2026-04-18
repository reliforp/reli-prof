# rmem:serve — Persistent Query Server

## Status

Design proposal. Not yet implemented.
Reviewed by codex (see rmem-serve-review-notes.md).

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
forks a query child after substrate construction. Parent runs the
TUI, child serves the socket. Substrate is shared via CoW.

A standalone `rmem:serve` (headless) wraps the same query service
for CI / AI-only use cases.

```
┌──────────────────────────────────────────────┐
│  rmem:explore --serve=/path/to/sock          │
│                                              │
│  load substrate + model                      │
│  prewarm shared caches                       │
│  clear build-only cast caches                │
│  fork()                                      │
│  ├─ parent: TUI loop (unchanged)             │
│  └─ child:  socket accept → query → respond  │
│             (read-only, never mutates TUI)    │
│                                              │
│  Substrate shared via CoW (FFI + PHP arrays) │
└──────────────────────────────────────────────┘
        ↕ Unix domain socket
┌──────────────┐  ┌──────────────┐
│  rmem:query   │  │ AI assistant │
│  --server=... │  │ (Bash tool)  │
└──────────────┘  └──────────────┘
```

## Protocol

### Transport

Unix domain socket (SOCK_STREAM). Path convention:
`$XDG_RUNTIME_DIR/reli/rmem-serve/<server-id>.sock` with 0700
directory permissions. Falls back to `/tmp/reli-rmem-<pid>.sock`
when `$XDG_RUNTIME_DIR` is not set.

### Action namespaces

Actions are namespaced to separate concerns:

- `query.*` — read-only data queries (always available)
- `ui.*` — TUI state read/write (requires `--serve-control`, phase 2)
- `server.*` — lifecycle management

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
{"action": "query.filter", "node_id": 123, "pattern": "password", "limit": 100}
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
```

All list responses include `total_count`, `truncated`, and `limit`
fields. Default limit is 100 for children/parents/rankings, 50 for
top_retained, configurable per-request.

### server.hello response

Returns file identity and protocol version for client validation:

```json
{
  "ok": true,
  "data": {
    "protocol_version": 1,
    "server_id": "a1b2c3",
    "rmem_path": "/path/to/output.rmem",
    "rmem_size": 1234567890,
    "node_count": 34000000,
    "edge_count": 60000000
  }
}
```

### Composite actions

`query.sandwich` returns parents + node_detail + children in one
response, avoiding 3 round-trips:

```json
{
  "ok": true,
  "data": {
    "parents": [...],
    "detail": {"type": "...", "class": "...", ...},
    "children": [...]
  },
  "truncated": {"parents": false, "children": true}
}
```

`query.subtree_stats` returns type/class breakdown under a node.
Bounded by `max_nodes` and `max_depth` to prevent runaway walks:

```json
{
  "ok": true,
  "data": {
    "total_retained": 12345678,
    "node_count": 456,
    "type_breakdown": [{"type": "ZendArray", "count": 200, "total": 5000000}, ...],
    "class_breakdown": [{"class": "User", "count": 50, "total": 3000000}, ...],
    "truncated": false
  }
}
```

## Implementation

### RmemQueryService

A new class that converts RmemModel method results into protocol
response format. Shared between explore-integrated mode and
standalone rmem:serve:

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

### Prewarm before fork

Shared caches should be populated before fork so both parent and
child benefit without CoW duplication:

```php
// Before fork:
$model->ensureLocationInfoLoaded();  // addresses, string values, refcounts, classes
$model->getClassRanking();           // cached ranking
$model->getTypeRanking();            // cached ranking
$model->buildDefinitionIndexes();    // function/class definition lookup
$reader->clearCastCache();           // drop build-only FFI copies
```

Lazy caches created after fork (e.g. subtree_stats, search results)
are worker-local. This is fine — they are transient and should be
bounded by response limits.

### Process model

```
parent:
  open rmem
  build substrate/model (skipScc: true)
  prewarm shared indexes
  clear build-only cast caches
  fork query child
  run normal TUI loop (unchanged)

child:
  create unix socket (owner-only directory)
  write PID to <socket_path>.pid
  accept loop:
    read newline-delimited JSON
    route to RmemQueryService
    write JSON response
  handle SIGTERM → unlink socket → exit
```

### Client integration

For CLI:

```bash
# One-shot query
echo '{"action":"query.children","node_id":123}' | \
  nc -U $XDG_RUNTIME_DIR/reli/rmem-serve/abc123.sock

# Or a thin PHP wrapper
php reli rmem:query --server=$XDG_RUNTIME_DIR/reli/rmem-serve/abc123.sock \
  --node=123 --children
```

For AI assistants:

```bash
# Direct socket query via Bash tool
echo '{"action":"query.sandwich","node_id":123}' | \
  nc -U $XDG_RUNTIME_DIR/reli/rmem-serve/abc123.sock | jq .
```

## Memory: CoW analysis

### FFI C buffers

FFI CSR buffers (int32/int64 arrays allocated by FFI::new) live in
the C heap. After fork, both processes map the same physical pages.
As long as the query child treats them as read-only, no CoW page
faults occur on the bulk data. These are expected to share well.

### PHP arrays and CData wrappers

Both FFI CData wrappers and PHP arrays are refcounted zvals. When
the child process accesses one, the refcount increment triggers a
CoW copy — but only of the page containing the refcount header
(zend_refcounted_h). The actual data (C buffer behind CData, Bucket
table behind PHP array) resides on separate pages and remains shared.

However, PHP array CoW behavior is less predictable than FFI C
buffers. zval, HashTable, and allocator metadata can be dirtied by
runtime operations beyond simple reads. Memory assumptions should
be validated with PSS / Private_Dirty measurements, not RSS.

### Measurement

Validate CoW effectiveness using `/proc/<pid>/smaps_rollup`:

```
parent after load:         Pss, Private_Dirty
child immediately after fork:   Pss, Private_Dirty
child after query.node_detail:  Pss, Private_Dirty
child after class_ranking:      Pss, Private_Dirty
child after subtree_stats:      Pss, Private_Dirty
```

This shows which lazy operations increase private memory. Use the
results to classify operations as "prewarm before fork" vs
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
- Parent monitors child via `pcntl_waitpid(WNOHANG)` in TUI loop;
  restarts on unexpected exit

### Response limits

All list-returning actions enforce default limits to prevent
multi-GB JSON responses:

| Action | Default limit | Configurable |
|--------|--------------|-------------|
| children, parents | 100 | yes |
| class_ranking, type_ranking | 50 | yes |
| top_retained | 50 | yes |
| nodes_by_class, nodes_by_type | 100 | yes |
| subtree_stats | max_nodes=100000, max_depth=50 | yes |
| filter | 100 | yes |

Responses include `total_count` and `truncated: true` when results
are clipped.

## UI navigation (phase 2)

### Design

Phase 1 is query-only. The child never mutates TUI state.

Phase 2 adds `ui.*` actions behind `--serve-control` opt-in:

```json
{"action": "ui.navigate_sandwich", "node_id": 12345}
{"action": "ui.navigate_roots"}
{"action": "ui.navigate_back"}
{"action": "ui.get_current_focus"}
{"action": "ui.get_current_selection"}
```

`ui.*` actions require IPC between child and parent (pipe pair).
The parent TUI loop must be modified to check the pipe — either
via `stream_select` on tty + pipe, or via a timeout-based poll.

`ui.*` responses include a `ui_revision` counter. Navigation
requests can include `if_revision` to reject stale navigations.

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

## Alternatives considered

### HTTP server

Heavier than needed. JSON over TCP adds HTTP parsing overhead.
Unix socket is simpler, faster, and avoids port management.
Could add HTTP as a future option if remote access is needed.

### stdin/stdout line mode

Works well for single-session use but cannot be shared between
explore and AI, and background process stdin management is awkward.

### MCP (Model Context Protocol) server

Good integration with Claude Code but vendor-specific. CLI users
get no benefit. A socket server is more universal — an MCP adapter
can wrap it later if needed.

## Implementation order

1. `RmemQueryService` — extract model-to-protocol conversion from TUI
2. `query.*` minimal set: `server.hello`, `query.node_detail`,
   `query.path_to_root`, `query.children`, `query.parents`,
   `query.sandwich`
3. `rmem:explore --serve --fork-query-server` — parent TUI, child
   query-only socket server
4. Response limits: `limit`, `truncated`, `total_count`
5. Socket security: `$XDG_RUNTIME_DIR`, owner-only dir, server_id
6. Protocol: `server.hello` with file_identity + protocol_version
7. Additional queries: `find_by_address`, definition lookup,
   class/type/top_retained ranking, `subtree_stats`
8. Standalone `rmem:serve` — headless wrapper around RmemQueryService
9. PSS / Private_Dirty measurement to validate CoW assumptions
10. Phase 2: `ui.*` IPC via pipe, TUI input loop timeout/stream_select
