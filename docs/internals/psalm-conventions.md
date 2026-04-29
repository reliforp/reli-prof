# Psalm Conventions

This document captures the conventions established across PRs #712, #713,
#714, #718 and the follow-ups. Read it before you reach for `(int)`, an
`assert()`, or a `@psalm-suppress` annotation.

## TL;DR

| Want to … | Use | Don't use |
| --- | --- | --- |
| Narrow a value's type for Psalm in a non-hot path | `PhpCast\Cast::toInt()` / `::toFloat()` / etc. | `(int) $x`, `(float) $x` |
| Narrow a value's type in a hot path | `(int) $x` + a `// hot path: …` comment + a `psalm-baseline.xml` entry | `Cast::toInt()` per element |
| Convince Psalm a value is non-null | bare `assert($x !== null)` | `Webmozart\Assert::notNull()` |
| Validate a real runtime contract | `Webmozart\Assert::*` or `if (...) throw` | bare `assert(...)` |
| Suppress one specific Psalm issue at one site | `@psalm-suppress IssueName` with a comment explaining why | a wholesale class- or file-level suppress, or a `@var` cast |

## Why

The project enables Psalm at `errorLevel="1"` with `findUnusedBaselineEntry="true"`, and the production Docker image runs PHP with `zend.assertions=-1`. Together these encourage three split decisions:

1. **Type narrowing for Psalm vs. real runtime checks.** With `zend.assertions=-1`, every bare `assert(...)` is stripped at compile time in production. That makes bare `assert()` exactly right for "I want Psalm to narrow this type" and exactly wrong for "I want to crash with a clear message if this invariant breaks at runtime". The latter goes through `Webmozart\Assert` (a regular method call, unaffected by `zend.assertions`) or an explicit `if (...) throw new …`.

2. **Coercion intent.** `(int) "abc"` silently returns `0`. `(int) [1,2,3]` silently returns `1`. `(int) true` silently returns `1`. None of these emit a warning, even with `strict_types=1`. `PhpCast\Cast::toInt()` (the project already pulls in `sj-i/php-cast`) keeps PHP's loose-mode integer coercion *with* a `TypeError` on uncoercible values like arrays and objects — the fail-loud variant. The hot-path exception is purely about per-call method-dispatch cost; the resulting `InvalidCast` baseline entries document the trade-off and live next to a comment at the site.

3. **Baseline as a checked-in artefact, not a rug.** `findUnusedBaselineEntry="true"` means any time a fix obviates a baseline entry, Psalm yells `UnusedBaselineEntry` until you remove the entry. So entries that survive there really do represent something we couldn't (or chose not to) fix in code. Use `psalm.phar --set-baseline=psalm-baseline.xml`, **not** `--update-baseline`, when you want a fresh baseline — `--update-baseline` only prunes, it does not add newly-surfaced errors.

## Where types live

When Psalm's CallMap or stubs are wrong for our usage, fix them at the source:

| Concern | File |
| --- | --- |
| `unpack()` format-aware return type | `tools/stubs/php/unpack.php` |
| FFI's `CData::offsetGet/Set` (engine-level array access) | `tools/stubs/ffi/ffi.php` |
| FFI templated `CArray<T>` element types | `tools/stubs/ffi/scalar.php` |
| FFI structs we read from the .rmem binary format (`NodeRow`, `EdgeRow`, `LocationRow`) and Zend internals | `tools/stubs/ffi/php.php` |
| `Reader::castSection('SECTION', 'EdgeRow')` returning the right typed array | `@psalm-return` literal-string conditional on the method itself |

Those live at the type-system layer and benefit everyone using the helper. They beat sprinkling `@var` hints at every call site, which lie quietly when the underlying code drifts.

## When to add a `@psalm-suppress`

Acceptable when **all** of these hold:
1. The site really is fine at runtime — the code is correct, Psalm just can't prove it.
2. There is a comment immediately above the suppress explaining the invariant.
3. The suppress is targeted to a specific issue name, not a wholesale class- or file-level catch-all.

Examples that meet the bar:
- `@psalm-suppress PossiblyInvalidArrayAccess` above a generated FastPath reader, with a comment that the preceding `regionFor($address, $size)` call guarantees the buffer covers the unpack target. (Encodes a buffer-size invariant that PHP's type system can't carry.)
- `@psalm-suppress PossiblyInvalidArgument` on `loadFfiSection()` for `FFI::addr($buf[$elemOffset])`, with a comment that ext/ffi narrows `int32_t[]` element access in a way the upstream stub doesn't.

Examples that don't:
- `/** @psalm-suppress MixedAssignment, MixedArgument, MixedOperand, MixedReturnStatement */` on a whole class because dealing with the underlying mixed types looked tedious. (No comment, multiple issues, hides the real problem.)
- `/** @var int */` in front of `unpack(...)[1]` to silence MixedReturnStatement. (Lies about the return type instead of teaching Psalm via a stub.)

## Hot paths

The following call sites are hot enough that we explicitly chose `(int)` over `Cast::toInt()`. They each have a comment at the site and a baseline entry; if you "clean them up", phpunit + the perf benchmarks will not catch the regression — please don't.

- `FfiIntSet::add()` / `::has()` — open-addressing seen-set, called per memory location during analyze
- `BinaryContextTreeSink::emitNode()` — same per-location frequency
- `BinaryMemoryOutput::write()` per-edge / per-row-ptr loops — O(edgeCount) CSR build, runs once per `inspector:memory:dump --format=binary` but `edgeCount` runs to the millions on real snapshots

For everything else (cold paths, report generation, one-shot writes), `Cast::toInt()` overhead measured at <0.1 % of total CPU on a 134 MB `.rmem` snapshot — see #714's PR description for the dogfooding numbers.

## Baseline maintenance

The dance, in order:
1. Make the code change.
2. `php vendor/bin/psalm.phar --no-cache --no-progress` — verify there are zero errors and the right number of unused baseline entries reported.
3. `php vendor/bin/psalm.phar --set-baseline=psalm-baseline.xml --no-cache --no-progress` — regenerate the baseline from the current state.
4. Run `phpunit` on the touched paths.

`--set-baseline` overwrites; `--update-baseline` only prunes. If you ever see Psalm pass locally but fail in CI on a `MixedAssignment` or similar, you almost certainly used `--update-baseline` when you wanted `--set-baseline`. Re-run with the latter.
