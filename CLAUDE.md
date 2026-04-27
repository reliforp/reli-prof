# CLAUDE.md — Project Notes for Claude Code

## Environment Setup

### Starting dockerd for integration tests

Integration tests (`#[Group('target-version')]`) require Docker to pull and run
PHP target images (`php:7.3-zts`, `php:8.4-cli`, etc.).  
**Always start dockerd at the beginning of the session** if you plan to run
integration tests or need to `docker pull`:

```bash
dockerd --storage-driver=vfs --bridge=none --iptables=false --ip6tables=false &
```

Wait a few seconds for dockerd to be ready before running tests.

### Proxy settings for Docker pulls

If `docker pull` fails with network errors, check whether HTTP proxy environment
variables are set and pass them to the daemon or configure
`/etc/systemd/system/docker.service.d/proxy.conf`.  Common variables:

```bash
export http_proxy=...
export https_proxy=...
export no_proxy=localhost,127.0.0.1
```

When `docker pull` keeps failing, retry a few times — transient network issues
are common in sandboxed CI-like environments.

### Running integration tests

```bash
# Run all integration tests for a specific PHP target
RELI_TEST_PHP_TARGETS=v73_zts php vendor/bin/phpunit --group target-version

# Run a specific test class
RELI_TEST_PHP_TARGETS=v74 php vendor/bin/phpunit --filter 'MemoryCompareCommandIntegrationTest'
```

### Sandbox-only ptrace failures

If `tests/Lib/Process/Exec/TraceeExecutorTest` (or anything else that calls
`PTRACE_TRACEME` from a forked child) returns `EPERM` here, that is a
seccomp/yama restriction in the sandbox container — the test is green on
`0.12.x` upstream. Don't chase it as a regression. `strace -e ptrace` on the
phpunit run will show the EPERM if you're unsure.

## Profiling reli (dogfooding)

reli is itself a PHP process, so you can profile it with another reli. Useful
when you suspect a hot path inside reli's own code.

### Standard recipe

```bash
# Inner reli runs against the real target; bigger sample interval (1 ms here)
# keeps the rbt manageable.
php ./reli inspector:trace -p "$TARGET_TID" \
    --php-regex='.*/libphp\.so$' \
    --libpthread-regex='.*/libc\.so.*' \
    > /tmp/inner.out 2>&1 &
INNER=$!

# Outer reli profiles the inner and writes a binary trace.
php ./reli inspector:trace -p "$INNER" \
    -F rbt -o /tmp/profile.rbt \
    -s 1000000 \
    > /dev/null 2>&1 &
OUTER=$!

# Wait for inner to do its thing, then INT both. Send INT, not KILL — the rbt
# is finalised on graceful shutdown only; SIGKILL leaves the file empty or
# truncated and `rbt:analyze` will bail with "Unexpected end of stream while
# decoding varint".
sleep 5
kill -INT $OUTER $INNER
wait
```

Variant: profile reli **from the very first PHP frame** (catches startup +
autoload too) by letting the outer reli launch the inner as a child:

```bash
php ./reli inspector:trace \
    -F rbt -o /tmp/profile.rbt -s 100000 \
    -E /tmp/inner.err -O /tmp/inner.out \
    -- php ./reli inspector:trace -p "$TARGET_TID" \
        --php-regex='.*/libphp\.so$' --libpthread-regex='.*/libc\.so.*'
```

### Analyzing the rbt

Use `rbt:analyze`, not `converter:folded` + ad-hoc `awk`. The folded format
collapses identical stacks into a single line with a count column at the end,
so `wc -l` and `sort | uniq -c` both undercount samples. `rbt:analyze` does the
right summation and surfaces self-time / total-time / callers / callees in one
shot:

```bash
# Reads from stdin
php ./reli rbt:analyze --top=15 --no-line --crop=140 < /tmp/profile.rbt

# Filter to a code path (PCRE matched against any frame in each stack)
php ./reli rbt:analyze --match='findGlobals|loadResolver' \
    --top=15 --no-line --crop=140 < /tmp/profile.rbt

# Who calls X?
php ./reli rbt:analyze --sections=callers --callers='^FFI::string$' \
    --no-line --crop=200 < /tmp/profile.rbt
```

### Sampling-resolution gotchas

- The PHP-frame sampler does **not** cover time the target spends in C-only
  code (FFI calls, syscalls). If `rbt:analyze` looks dominated by
  `time_nanosleep`, that is the post-cold-attach sampling loop sleeping —
  not a real hot spot. Filter cold-attach with `--match='findGlobals|...'`
  to focus on the relevant samples.
- The outer reli has its own startup cost (~1 s on this sandbox), so it can
  miss the **first** ~1 s of the inner reli's life when both are launched
  back-to-back. The `-- cmd` form above sidesteps that by having the outer
  fork the inner.
- The first attach to a fresh `libphp.so` is slower than subsequent
  ones (parses the dynamic symbol table, brute-forces TLS, etc.).
  Sub-second on a normal box, a few seconds on this sandbox. Don't
  `timeout 5s` the first run when investigating unfamiliar binaries.

## Project-Specific Pitfalls

### FrankenPHP cold attach reality

FrankenPHP is the canonical "this should be daemon-mode" target. A few
non-obvious things bite single-shot `inspector:trace -p` users:

- **`php-main` is the bootstrap thread, not a worker.** It matches `^php-`
  but does not host requests; memory commands fail with "failed to find
  ZendMM main chunk" and trace samples are useless. The recommended regex
  everywhere is `^php-[0-9a-f]+$`.
- **Hex-named workers can be uninitialised.** A worker that has never
  served a request leaves `_tsrm_ls_cache` zeroed, so brute force returns
  null. `PhpGlobalsFinder::findTsrmLsCache` re-checks PT_TLS in the ELF
  before treating that as a binary-level fact, so the negative result
  is not persisted and the next attach against a warm worker succeeds
  without intervention.
- **Sequential traffic warms one worker, not all of them.** When
  reproducing a "cold worker" failure interactively, fire `num_threads`+
  parallel slow requests rather than a single curl — Caddy reuses
  the first idle worker for serial requests, so the TID you grab from
  `ps` may stay cold no matter how many requests you throw at it.
- **`inspector:memory:dump` in regular (per-request) mode only works
  while the worker is mid-request.** The chunk finder reads
  `eg.current_execute_data` and walks 2 MB-aligned addresses to locate
  the request heap; between requests `current_execute_data` is 0 and
  the dump fails with "failed to find ZendMM main chunk". Reproduce
  with a long `usleep`-tail script and saturate the workers; CLI mode
  (`frankenphp php-cli script.php`) sidesteps it because the worker
  stays in PHP for the whole script. In production, prefer
  `inspector:watch --action=memory-dump` (which fires only when its
  condition holds, naturally inside a request) or `inspector:sidecar`
  (which is invoked from PHP itself). This is not a FrankenPHP quirk
  but a property of ZendMM, which is request-scoped in every SAPI.
- **FrankenPHP worker mode keeps the request heap alive between
  requests, so `inspector:memory:dump` works at any time.** A worker
  loaded with `frankenphp { worker /app/worker.php }` stays parked in
  the `frankenphp_handle_request()` loop on the PHP call stack while
  it waits for the next request, so `current_execute_data` is never
  zero. `inspector:trace` shows a 2-frame
  `frankenphp_handle_request` / `<main>` stack on idle workers and
  full handler stacks mid-request, and `memory:dump` succeeds on
  both — same dump size in both states (heap is the worker's, not
  the per-request scope). When verifying the docs against worker
  mode, expect the regular-mode "must be mid-request" caveat to NOT
  apply.
- **`module_registry` is preempted by the embedding executable.**
  `frankenphp` statically links libphp, so the dynamic linker binds the
  shared symbols to the executable's copy and leaves libphp.so's BSS at
  zero. `findModuleRegistry` therefore probes `/proc/{pid}/exe` first
  and falls back to the libphp.so reader only when the executable does
  not define it; do not "tidy up" by deleting that fallback ordering.
  The `*_offset` (TSRM) symbols are also preempted but `TsrmGlobalsResolver`
  already handles that with the `_offset == 0 → _id` fallback at
  `TsrmGlobalsResolver.php:111`. `PhpVersionDetector` must NOT cache the
  host-PHP fallback (`'v' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION`) when
  detection fails, because doing so silently mis-resolves chunk and
  struct layouts on every subsequent run against the same binary.
- **Tests in `tests/Lib/PhpProcessReader/CallTraceReader/FrankenPhpCallTraceReaderTest`
  share one `BinaryAnalysisCache` across thread iterations.** That's the
  regression test for the cache-poisoning fix; if you put a fresh cache
  back per iteration, the test passes again but the bug it pins also
  comes back.
- See `docs/tracing/frankenphp.md` for the user-facing version of the
  same warnings.

### File reading from /proc/<pid>/root/...

`Reli\Lib\File\NativeFileReader` opens files via FFI (libc `open`/`pread`/
`close`) instead of PHP's stream wrappers. PHP's stream layer runs paths
through `realpath()`, which for `/proc/<pid>/root/...` follows the symlink
back to `/` and ends up trying to open the **host's** copy of the binary,
not the container's. `file_get_contents` will silently succeed on the wrong
file or fail with ENOENT — that's why the FFI path exists.

If you ever need to add a fast read path for the cold-attach hot loop,
keep using FFI; replacing it with `file_get_contents` is a footgun.
`CatFileReader` (the `proc_open(['cat', $path], …)` fallback) is the only
sanctioned non-FFI alternative and is markedly slower because it pays a
shell process per read.

### ELF / link-map parsing hot paths

The cold-attach path parses tens of thousands of ELF symbols per binary.
Two patterns that look harmless are surprisingly expensive at that scale:

- **`offsetGet` loops on `ByteReaderInterface`** — `read32`/`read64` used
  to do four/eight ArrayAccess calls per integer. Prefer
  `unpack('V', $data->createSliceAsString(offset, 4))` /
  `unpack('V2', $data->createSliceAsString(offset, 8))` instead.
- **Per-record reader calls in tight parser loops** — for things like the
  symbol table where each entry is a fixed shape, read the entire blob
  via `createSliceAsString` once and `unpack(format, substr($blob, …))`
  per entry. One native `unpack` per entry beats half a dozen wrapper
  calls.
- **Byte-by-byte C-string reads from the target process** — the link-map
  walker used to do one `process_vm_readv` syscall per character. Read in
  chunks **clipped to the current page** (otherwise you cross an unmapped
  page boundary and trip EFAULT, which surfaces as `MemoryReaderException`
  and aborts the cold attach). See `LinkMapLoader::readCString`.

### FFI CData lifetime — recurring source of bugs

`FFI::cast()` returns a **view** into the parent buffer, not a copy.  
If the parent buffer is garbage-collected, the view becomes a dangling pointer.
Accessing fields on dangling CData returns garbage — often manifesting as
unexpected types (e.g., `struct _zend_array` where `size_t` was expected).

Key rules:

1. **`CastedCData->raw` must hold the original buffer**, not a sub-view.  
   This is the mechanism that keeps the buffer alive. If you pass a sub-view
   as `raw`, the buffer's lifetime is not anchored and GC can free it.

   ```php
   // BAD — sub-view does not anchor the buffer
   new CastedCData(
       $this->casted_cdata->casted->heap_slot,  // raw = sub-view!
       $this->casted_cdata->casted->heap_slot,
   );

   // GOOD — raw buffer is anchored
   new CastedCData(
       $this->casted_cdata->raw,                 // raw = original buffer
       $this->casted_cdata->casted->heap_slot,
   );
   ```

2. **Re-deref after obtaining a child from a parent structure** if the parent
   may go out of scope:

   ```php
   // BAD — $zval is a view into $bucket's CData
   $bucket = $arr->findByKey($dereferencer, $key);
   $zval = $bucket->val;

   // GOOD — independent CData copy
   $bucket = $arr->findByKey($dereferencer, $key);
   $zval = $dereferencer->deref($bucket->val->getPointer());
   ```

3. When reviewing or writing code that creates `CastedCData` for embedded
   structs (e.g., `ZendMmChunk->heap_slot`, `Bucket->val`), always verify
   that `raw` traces back to the original `unsigned char[]` buffer.

Full documentation: `docs/internals/ffi-cdata-lifetime.md`

## Code Quality

### Static analysis and linting

```bash
php vendor/bin/psalm.phar          # static analysis
php vendor/bin/phpcs --standard=PSR12 src/  # coding standard
php vendor/bin/phpunit             # unit tests (excludes target-version group)
```
