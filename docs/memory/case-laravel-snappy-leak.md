# Case study: tracking down `barryvdh/laravel-snappy` issue #536 with `reli`

A user reported a steady ~12–13 KB/job leak from `barryvdh/laravel-snappy`
v1.0.2 running inside a Laravel queue worker:

```php
class DummyJob implements ShouldQueue {
    public function handle(): void {
        Log::info('Start: '.(memory_get_usage() / 1024));
        $snappy = SnappyPdf::loadHTML('<div>Something</div>');
    }
}
```

`unset($snappy)` plus `gc_collect_cycles()` did not recover the memory, so
the reference holding the leaked object was not a PHP-side variable. This
note records how `reli`'s memory tooling pinpointed it.

## Reproduction

A bare PHP loop that mimics what Laravel's queue worker does between jobs
(`Facade::clearResolvedInstances()` then resolve `snappy.pdf.wrapper`
again) leaks at exactly the reporter's rate:

```
i=  0 mem=1029 KB
i= 20 mem=1282 KB
i= 40 mem=1534 KB
…
i=180 mem=3304 KB
```

`(3304 - 1029) * 1024 / 180 ≈ 12.94 KB / iteration`.

## What reli's snapshot diff says

Two `inspector:memory:dump` snapshots, taken 100 iterations apart, were
compared with `inspector:memory:compare`:

```
memory_get_usage()    1.18 MB → 2.42 MB    +1.23 MB
Heap total            2.00 MB → 4.00 MB    +2.00 MB   (one new chunk)
Analyzed              83.2%   → 40.8%      −42.4pt
```

The analyzed-coverage drop is the headline signal: the PHP-reachable
object graph grew by **2 nodes**, but the heap grew by **1.23 MB**.
Whatever is pinning the new allocations is not on any root that the
analyzer currently walks.

The bin histogram delta confirms the per-iteration shape — one of each
small allocation, 100 times over:

```
Bin   Size     Count Δ      Memory Δ      Shape
 14   224 B    +100         +21.88 KB     struct stat-like
 12   160 B    +100         +15.63 KB     unclassified
 10   112 B    +100         +10.94 KB     unclassified
  6    56 B    +200         +10.94 KB     HashTable headers (×2)
```

The bulk of the leak is in the second snapshot's large-run accounting:

```
ZendMM live: 1.90 MB in 130 large runs   (~14.6 KB per run)
```

100 iterations, 100 extra large runs of ~14 KB each — i.e. one fat
HashTable per iteration. The Knp `AbstractGenerator::configure()`
default option table is ~132 entries; its arData lands in a large bin.

So we are looking for *something not reachable from any walked root that
holds one fresh Pdf instance per iteration*.

## Pinning the reference holder

A one-line check that bypasses Laravel entirely:

```php
$fs = new Illuminate\Filesystem\Filesystem();
for ($i = 0; $i < 5; $i++) new IlluminateSnappyPdf($fs, '/bin/echo', [], []);
gc_collect_cycles();
$baseline = memory_get_usage();
for ($i = 0; $i < 500; $i++) {
    $p = new IlluminateSnappyPdf($fs, '/bin/echo', [], []);
    unset($p);
}
gc_collect_cycles();
// 12,920 bytes / iteration
```

Reading the constructor:

```php
// vendor/knplabs/knp-snappy/src/Knp/Snappy/AbstractGenerator.php
public function __construct($binary, array $options = [], ?array $env = null) {
    $this->configure();
    …
    if (\is_callable([$this, 'removeTemporaryFiles'])) {
        \register_shutdown_function([$this, 'removeTemporaryFiles']);
    }
}
```

`register_shutdown_function` stores the callable in
`EG(user_shutdown_function_names)`, an HashTable whose lifetime is the
PHP request/process. Because the callable is `[$this, …]`, every
instance is pinned for the rest of the process — invisible to PHP-side
GC and unreachable from `class_table` / globals / objects_store walks,
which is why `reli`'s analyzer saw it as a 1.77 MB block of orphan
allocations rather than a node in the reachable graph.

Removing that one line and re-running the same 500-iteration probe:

```
=== with patch (register_shutdown removed) ===
500 ctor+unset leaked: 0 bytes / iter

=== without patch (original snappy) ===
500 ctor+unset leaked: 12,920 bytes / iter
```

12,920 B/iter matches the issue reporter's number (≈ 12.5 KB) and the
heap growth observed in the reli diff to within ZendMM rounding.

## Why it bites Laravel users specifically

`Barryvdh\Snappy\ServiceProvider::boot()` binds both `snappy.pdf` and
`snappy.pdf.wrapper` with `$app->bind(...)`, not `singleton(...)`.
Between queue jobs, Laravel calls `Facade::clearResolvedInstances()`,
so the next `SnappyPdf::loadHTML(...)` resolves a brand-new `Pdf` from
the container — and registers another shutdown function holding it.
Single-request web traffic never sees this because the process exits
between requests and `EG(user_shutdown_function_names)` is freed with
it. Long-running workers (queue, Octane, Horizon) accumulate one
pinned `Pdf` per job until `memory_limit` is reached.

## Suggested fixes (upstream)

- `knplabs/knp-snappy`: stop registering a shutdown function for
  per-request cleanup — `__destruct` already calls
  `removeTemporaryFiles`, and the shutdown registration is what keeps
  `__destruct` from ever firing in a long-running process. If a
  fallback is required, register it once per binary path with a weak
  reference, or move temp-file cleanup to an explicit method.
- `barryvdh/laravel-snappy`: change the two `bind` calls to
  `singleton` so workers reuse one `Pdf` instance; this is also a
  meaningful CPU win because `AbstractGenerator::configure()` rebuilds
  ~132 default options every job.

## Reli commands used

```bash
# capture
php ./reli inspector:memory:dump -p "$PID" -o snapA.rmem
# (run more iterations)
php ./reli inspector:memory:dump -p "$PID" -o snapB.rmem

# convert raw dumps to analyzed .rmem
php ./reli inspector:memory:analyze snapA.rmem -o snapA.analyzed.rmem -f rmem
php ./reli inspector:memory:analyze snapB.rmem -o snapB.analyzed.rmem -f rmem

# diff
php ./reli inspector:memory:compare snapA.analyzed.rmem snapB.analyzed.rmem \
    --threshold=1 --diff-nodes=target-only

# per-snapshot view
php ./reli inspector:memory:report snapB.analyzed.rmem
```

The decisive signal was `inspector:memory:report`'s "Only 26.9% of
heap analyzed — 1.77 MB unaccounted" line: when a long-running PHP
process has a large pool of memory invisible to the reachable graph,
`EG(user_shutdown_function_names)` is one of the first places to
check.
