# php-meminfo compatible export

reli can re-emit its memory graphs as JSON dumps compatible with
[php-meminfo](https://github.com/BitOne/php-meminfo). If you already have
analysis infrastructure built around php-meminfo — its `bin/analyzer`
(`summary` / `query` / `ref-path` / `top-children`), dashboards, or custom
scripts that consume `meminfo_dump()` output — you can point it at reli
captures without installing the extension into the target, and without
changing your tooling.

## Producing a dump

From an existing `.rmem` capture (fast path, no re-analysis):

```bash
php ./reli inspector:memory:export-meminfo snap.rmem meminfo.json
# or to stdout
php ./reli inspector:memory:export-meminfo snap.rmem > meminfo.json
```

Directly from a live process:

```bash
php ./reli inspector:memory -p <pid> -f meminfo -o meminfo.json
```

From a raw memory dump (`.rdump`, recommended for production — the target
is only stopped for the dump, not the analysis):

```bash
php ./reli inspector:memory:dump -p <pid> -o dump.rdump
php ./reli inspector:memory:analyze dump.rdump -f meminfo -o meminfo.json
```

## Consuming the dump

Anything that reads php-meminfo dumps works as-is, e.g. with the analyzer
from the php-meminfo repository:

```console
$ bin/analyzer summary meminfo.json
+----------+-----------------+-----------------------------+
| Type     | Instances Count | Cumulated Self Size (bytes) |
+----------+-----------------+-----------------------------+
| string   | 311             | 23597                       |
| int      | 306             | 4896                        |
| MyClassB | 101             | 7272                        |
| MyClassA | 100             | 8800                        |
...

$ bin/analyzer query -v -f "class=MyClassA" -f "is_root=0" meminfo.json

$ bin/analyzer ref-path 0x7f6311c04060 meminfo.json
Found 2 paths
Path to 0x7f6311c59b28
(main())$held["0"]
Path to 0x7f6311c59af0
(<CLASS_STATIC_MEMBER>)$MyClassA::registry["key-0"]
```

## What maps how

The output mirrors what `meminfo_dump()` writes:

- `header`: `memory_usage` / `memory_usage_real` /
  `peak_memory_usage` / `peak_memory_usage_real`, read from ZendMM's
  bookkeeping in the target (`heap->size` / `real_size` / `peak` /
  `real_peak`). Unlike the extension, no PHP needs to run inside the
  target to obtain them.
- `items`: keyed by target-process addresses (`0x…`), exactly like
  php-meminfo's `%p` item ids. Objects are keyed by the `zend_object`
  address, strings by the `zend_string`, arrays by the `zend_array`.
  Scalars have no own allocation (they live in their container's
  buckets), so they get synthetic ids in a reserved range
  (`0x1000000000000xxx`) that cannot collide with real addresses.
- `children` of objects map property names directly to value ids
  (declared and dynamic properties alike); `children` of arrays map
  keys to value ids. reli's internal indirection nodes (property
  tables, element wrappers, `zend_reference`) are collapsed away, so
  paths look the way php-meminfo users expect. Closures additionally
  expose their `use`d variables as a `static` child, mirroring the
  debug-handler view php-meminfo dumps.
- Roots are call-frame variables (`frame` = `"func()"`, the outermost
  frame is `"<GLOBAL>"`), global variables (`"<GLOBAL>"`), and class
  static properties (`"<CLASS_STATIC_MEMBER>"`, `symbol_name` =
  `"Class::prop"`). When several symbols reference one value, the
  first one wins — the same dedup the extension applies.
- `object_handle` is the real zend object handle, recovered from the
  objects-store bucket index.
- Scalar type names follow the target's PHP version like
  `zend_get_type_by_const()` does: `int` / `bool` on PHP 8 targets,
  `integer` / `boolean` on PHP 7 targets.

## Differences from the extension (mostly upgrades)

- **Coverage.** php-meminfo only walks symbol tables and statics; reli
  additionally emits every object in the objects store, including ones
  unreachable from any root (cycle garbage waiting for GC, values held
  only by internal structures). Those show up with `is_root: false`
  and, if truly unreachable, no `ref-path` — exactly the shape their
  absence of roots implies.
- **Sizes.** php-meminfo reports `sizeof(zval) + sizeof(zend_object)`
  for every object and ignores property tables and array storage; reli
  reports measured allocation sizes (object size includes the
  property table, string size is the real `zend_string` allocation,
  array size covers the header). Scalars are reported as
  `sizeof(zval)` = 16 to match php-meminfo accounting.
- **No in-process execution.** The dump is taken from outside; frames
  of internal function calls appear with synthetic
  `$args_to_internal_function[N]` symbols instead of being invisible.
- **Extension fields.** Items carry two extra fields, prefixed so they
  cannot collide with upstream: `#reli_node_id` (the node in the
  source rmem — feed it to `rmem:explore` / `rmem:query` to jump back
  into reli tooling) and `#reli_value` (a preview of string/scalar
  values, capped at 256 bytes). php-meminfo's own analyzer ignores
  unknown fields.
- Dumps produced by reli versions before the `value_type` node
  attribute existed re-export with `true` folded into `int 1` (the
  binary format stored both as `"1"`). Re-capture to get exact
  bool/int separation.

## Caveats

- The whole item graph is held in memory while converting; for very
  large rmems use `--memory-limit` to raise the exporter's own limit.
- `peak_memory_usage` falls back to the current usage when the source
  rmem predates peak tracking in the summary (older reli versions).
- Interned strings, opcache-shared structures, class definitions, op
  arrays etc. are not items (php-meminfo has no representation for
  them); use `rmem:explore` / `inspector:memory:report` when you need
  reli's full graph.
