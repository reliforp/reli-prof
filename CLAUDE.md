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

## Project-Specific Pitfalls

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
