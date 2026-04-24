# Similar tools, and when to pick reli

Where reli overlaps with other PHP profilers / VM inspectors, and
when you should reach for which. Currently only covers
[adsr/phpspy](https://github.com/adsr/phpspy) — the project reli
was heavily inspired by — but this is the intended home for any
future comparison.

## phpspy

reli started out heavily inspired by [adsr/phpspy](https://github.com/adsr/phpspy); several things have since diverged.

### Structural difference

The main structural difference is that reli is written in almost pure PHP while phpspy is written in C. If you want to customise *what* and *how* information is captured, doing it in PHP is easier — at some performance cost. (Though we aim to keep that cost modest.)

### ZTS PHP

reli can find VM state from ZTS interpreters: daemon-mode traces of threads started via [ext-parallel](https://github.com/krakjoe/parallel) are captured automatically, which phpspy alone cannot do. `inspector:eg` exposes just the EG address so you can feed it to phpspy manually for ZTS targets, and the [hybrid phpspy mode](tracing/phpspy-hybrid.md) (`phpspy:trace` / `phpspy:daemon`) combines reli's ZTS-aware EG resolution with phpspy's fast C-based tracing.

### Capabilities reli has that phpspy doesn't (currently)

- More accurate line numbers.
- Output format customisation via PHP templates.
- Running-opcode output for each sample.
- Automatic PHP-version detection from stripped binaries.
- Compact binary trace format (`.rbt`) plus speedscope / pprof / folded / callgrind / flamegraph converters (see [tracing/binary-trace-format.md](tracing/binary-trace-format.md)).
- Deep memory-graph analysis of the target process.
- Merged native (C-level) stack traces via DWARF `.eh_frame` unwinding.
- JIT-compiled function-name resolution via perf map / GDB JIT interface.

Nothing above is technically unreachable from phpspy — these may land there one day.

### Capabilities phpspy has that reli doesn't (currently)

phpspy still wins on raw sampling throughput and overhead. Much of what phpspy uniquely does will be covered by reli eventually.

### When to pick which

- **phpspy** — if you want the lowest-overhead pure-C sampler for a straightforward NTS PHP target and don't need deeper inspection (memory-graph analysis, native traces, opcode detail, variable capture at sample time, etc.).
- **reli** — if you need any of those deeper capabilities, want to customise capture/output in PHP, or need ZTS support without extra plumbing.
- **hybrid (`phpspy:trace` / `phpspy:daemon`)** — if you want phpspy's sampling speed on a ZTS target, or on any target where you prefer phpspy-rendered output but need reli's EG resolution. See [tracing/phpspy-hybrid.md](tracing/phpspy-hybrid.md).
