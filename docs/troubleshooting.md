# Troubleshooting

## "regex used to filter /proc/{pid}/maps matched nothing" / "php module not found"

Both of these surface the same root cause: reli's default `--php-regex` did not match any module in the target's `/proc/<pid>/maps`. The default is wide enough to cover unversioned (`php`, `php-fpm`), versioned (`php7.4`, `php8.4`, `php-fpm8.4`), and shared-library (`libphp.so`, `libphp7.so`, `libphp8.so`) layouts, but anything else — a custom `php-cli-foo` rename, a build whose libphp is named without the `libphp` prefix, or a target that mounts the interpreter at a non-standard path — will hit this. Use `--php-regex` to specify the executable (or shared object) that contains the PHP interpreter:

```bash
# Inspect the target's maps to see what to match against
sudo cat /proc/<pid>/maps | grep -E 'r-xp.*\.so|/php'

# Match a specific Debian/Ubuntu binary path you saw in /maps
sudo php ./reli inspector:trace -p <pid> --php-regex='/usr/bin/php8\.4$'

# Or, allow any versioned name under /usr/bin/
sudo php ./reli inspector:trace -p <pid> --php-regex='/usr/bin/php[0-9.]*$'
```

The right-hand side of `--php-regex` is a PCRE that's matched against the path column of `/proc/<pid>/maps`; anchor the pattern (`$`) to avoid matching unrelated `.so`s that happen to contain "php" in their name.

For FrankenPHP specifically, PHP is loaded as `libphp.so` and pthread lives in `libc.so`; see [tracing/frankenphp.md](tracing/frankenphp.md) for the full set of flags (`--php-regex`, `--libpthread-regex`, `--target-thread-regex`).

## I don't think the trace is accurate.

The `-S` option will give you better results. Using this option stops the execution of the target process for a moment at every sampling, but the trace obtained will be more accurate. If you don't stop the VMs from running when profiling CPU-heavy programs such as benchmarking programs, you may misjudge the bottleneck, because you will miss more VM states that transition very quickly and are not detected well.

## I can't get traces on Amazon Linux 2.

First, try `cat /proc/<pid>/maps` to check the memory map of the target PHP process. If the first module does not indicate the location of the PHP binary and looks like an anonymous region, try to specify `--php-regex="^$"` as an option.

## "Operation not permitted" / ptrace denied when attaching

`inspector:trace`, `inspector:memory`, `inspector:peek-var`, `inspector:watch`, `inspector:sidecar` and friends all rely on `ptrace(2)` / `process_vm_readv(2)`. If attach fails with `EPERM`, walk the checklist:

- **Permission**. Run reli as `root`, or grant `CAP_SYS_PTRACE` to the `php` binary you use to run reli (`sudo setcap cap_sys_ptrace=eip $(readlink -f $(which php))`).
- **Docker wrapper**. Make sure you installed the **full** profile (`docker:print-wrapper`, not `--profile=minimal`). The minimal profile drops `CAP_SYS_PTRACE`, `--pid=host`, `--security-opt=apparmor=unconfined`, and `--network=host` on purpose — re-install with `--profile=full` for live attach. See [docker-wrapper.md](docker-wrapper.md).
- **Yama / `ptrace_scope`**. On hardened hosts, `cat /proc/sys/kernel/yama/ptrace_scope` returning `2` or `3` blocks ptrace even for root. Temporarily lower it (`echo 1 | sudo tee /proc/sys/kernel/yama/ptrace_scope`), or use a native install.
- **AppArmor / SELinux**. Some distros confine `docker` and similar tools with profiles that block ptrace. Pass `--security-opt=apparmor=unconfined` (already set by the full Docker wrapper).
- **PID namespace**. In containers, the target process must be visible inside reli's PID namespace. The Docker wrapper passes `--pid=host`. For Kubernetes / docker-compose, set `shareProcessNamespace: true` / `pid: "service:app"`. The sidecar specifically requires this — see [monitoring/sidecar.md](monitoring/sidecar.md).

## I installed `reli-view` and live-attach commands fail

`reli-view` is the **minimal** Docker wrapper profile — viewers / converters only (`rbt:explore`, `rmem:explore`, `converter:*`, `inspector:memory:report`, `inspector:memory:compare`, …). It deliberately omits `CAP_SYS_PTRACE`, `--pid=host`, and `--network=host`, so any command that attaches to a live process or binds to a host port will fail.

Re-install with the full profile and retry:

```bash
eval "$(docker run --rm reliforp/reli-prof docker:print-wrapper)"
```

The two profiles can coexist — see [docker-wrapper.md](docker-wrapper.md) for the side-by-side install pattern (`--name=reli` / `--name=reliv`).

## "FFI extension not loaded" / "PCNTL not available"

reli relies on FFI for VM struct layout and PCNTL for daemon mode. The two extensions are bundled in the official Docker image (use the wrapper — see [getting-started.md § 1. Install](getting-started.md#1-install)). For native installs, build PHP with `--with-ffi` and `--enable-pcntl`, or install your distro's `php-ffi` / `php-pcntl` packages.

The sidecar **client** (the application-side library) does not require FFI — only the sidecar server / reli binary itself does. See [monitoring/sidecar.md § Client Library](monitoring/sidecar.md#client-library).

## `rmem:explore` won't open my file

`rmem:explore`, `rmem:query`, `rmem:serve`, and `rmem:mcp` read **`.rmem` only** (binary memory snapshots produced by `inspector:memory -f rmem`, `inspector:memory:dump` + `inspector:memory:analyze -f rmem`, or `inspector:coredump -f rmem`). Pointing any of them at a SQLite snapshot fails with `Invalid rmem magic: 53514c69` (the hex is `"SQLi"`, the start of the SQLite file header).

If your snapshot is a SQLite `.db` / `.sqlite` file, either re-capture with `-f rmem -o snapshot.rmem`, or use the SQL-aware analysers instead (`inspector:memory:report`, `inspector:memory:compare`, raw `sqlite3` / `duckdb`). To convert an existing dump, run `inspector:memory:analyze <dump> -f rmem -o snapshot.rmem`.

See [memory/memory-dump.md](memory/memory-dump.md) and [memory/rmem-explore-and-serve.md](memory/rmem-explore-and-serve.md).

## FrankenPHP: "no such PID" or empty trace when targeting the parent

A FrankenPHP process is one OS process with many threads, but only the PHP worker threads carry valid executor globals. Targeting the Caddy parent PID gives no PHP state.

Pass a **PHP worker TID** to `inspector:trace -p`, and add the three FrankenPHP flags (`--php-regex='.*/libphp\.so$'`, `--libpthread-regex='.*/libc\.so.*'`, plus `--target-thread-regex='^php-[0-9a-f]+$'` for `inspector:daemon` / `inspector:top` / `inspector:watch`). Full walkthrough: [tracing/frankenphp.md](tracing/frankenphp.md).

## "global symbol not found executor_globals" on FrankenPHP / ZTS.

ZTS (FrankenPHP, embed-SAPI, custom ZTS builds) keeps `executor_globals` in TLS, so reli has to find `_tsrm_ls_cache` by brute-forcing the target thread's TLS block. If the chosen thread happens to never have served a request — its TLS slot is still zero — the search returns nothing.

Recovery:

1. Pick a different worker TID and retry. Once any one thread succeeds, the cached TLS offset works for every other thread of the process.
2. Push some traffic through the server first so workers are warm, then attach.

`inspector:daemon` and `inspector:trace` retry internally and will recover from a transient idle-worker miss without help; `inspector:memory:dump` / `inspector:memory` / `inspector:sidecar` exit on the first failure, so the worker-choice workaround matters most for those. For FrankenPHP-specific guidance see [tracing/frankenphp.md](tracing/frankenphp.md#memory-and-watch-commands).

> **Note:** older versions of reli could persist `has_tsrm_ls_cache: false` for a ZTS binary the first time an idle worker was hit. The current code re-checks the binary's PT_TLS on every cache read and drops the entry if the binary is in fact ZTS, so upgraders do not need to `./reli cache:clear` — the stale entry self-heals on the next attach.

## Something seems stale after a PHP upgrade or container rebuild.

reli caches expensive binary-analysis results (ELF symbol resolution, ZTS TLS offsets, PHP version detection) under `~/.cache/reli/binary-analysis/`. If you suspect a stale entry is being served — after upgrading the target PHP, rebuilding a container image, or any other "shouldn't that have worked?" moment — drop or bypass the cache:

```bash
./reli cache:clear                              # drop everything
./reli inspector:trace --no-cache -p <pid>      # bypass for one invocation
```

For what is cached and how keys are computed, see [internals/binary-analysis-cache.md](internals/binary-analysis-cache.md).
