# Memory Analysis Optimization Ideas

## Problem

The memory analysis feature (collecting and analyzing PHP process memory via
`MemoryLocationsCollector`) has high memory consumption in the profiler process
itself. The peak occurs at the end of the collection phase, when the full
`ReferenceContext` tree and all `MemoryLocations` are held in memory
simultaneously.

The memory footprint scales with the target process's complexity (number of
objects, arrays, strings, functions, classes, etc.), and the composition varies
per target, so optimizations must be broadly effective rather than targeting
specific context types.

## Architecture Overview

Key data structures at peak:

- **ReferenceContext tree**: 48 concrete context classes, each holding child
  references in `$referencing_contexts` (`array<string, ReferenceContext>`) via
  the `ReferenceContextDefault` trait.
- **MemoryLocations**: `array<int => MemoryLocation>` collecting all memory
  regions. 32 MemoryLocation subclasses.
- **ContextPools**: 6 pools (String, Array, Object, PhpReference, Resource,
  UserFunctionDefinition) holding strong references keyed by address for
  deduplication.

## Proposed Optimizations

### 1. Convert `$referencing_contexts` array to typed properties (high impact)

Many context classes have a fixed, statically known set of child link names.
For example, `ArrayHeaderContext` only ever has an `array_elements` link. The
current design stores these in a generic `array<string, ReferenceContext>`,
paying PHP array bucket overhead (~36 bytes) plus string key cost per entry.

**Approach**: For context classes with known child types, replace the
`$referencing_contexts` array with dedicated typed properties (e.g.,
`public ?ArrayElementsContext $array_elements = null`). Context classes with
truly dynamic children (e.g., `ArrayElementsContext` whose keys are runtime
array keys) would keep the array.

**Effect**: Eliminates array bucket + string key overhead per link. Applies
uniformly across all context types.

### 2. Inline MemoryLocation into Context (high impact)

Currently each Context that has a location holds a separate MemoryLocation
object. Every PHP object carries ~56 bytes of header overhead.

Survey of the 48 context classes:
- **21 classes** have no MemoryLocation (category A)
- **25 classes** have exactly one MemoryLocation (category B)
- **2 classes** have two MemoryLocations (category C: `DefinedFunctionsContext`,
  `OpArrayContext`)

**Approach**: For category B (the majority), inline the MemoryLocation's fields
(`address`, `size`, and subclass-specific fields like `refcount`, `type_info`,
`value`) directly as properties of the Context class. Introduce a
`LocatedReferenceContext` interface with a `getMemoryLocation()` method that
reconstructs a MemoryLocation on demand. Category C (only 2 classes) gets
individual treatment.

**Effect**: Saves ~56 bytes per Context instance (object header of the
eliminated MemoryLocation). Since the most numerous context types (String,
Array, Object) are all category B, savings scale with target process complexity.

### 3. Truncate/lazy-load string values (target-dependent impact)

`ZendStringMemoryLocation::$value` stores the full string content read from the
target process. For string-heavy applications this can be significant.

**Options**:
- Store only the first N bytes + length for display purposes.
- Omit the value entirely during collection and re-read from the target process
  on demand during analysis (requires the target process to still be alive).

**Effect**: Highly dependent on target process. Large impact for applications
with many large strings; minimal for others.

### 4. SoA (Structure of Arrays) for MemoryLocations collection (medium impact)

`MemoryLocations` stores `array<int => MemoryLocation>` — an associative array
of objects. Each entry incurs both array bucket overhead and object header
overhead.

**Approach**: Replace with parallel arrays (or `SplFixedArray` / FFI buffers)
for address, size, and type fields. Eliminates per-entry object headers.

**Effect**: Reduces overhead for the flat MemoryLocations collection. Less
impactful than optimizations 1-2 if the ReferenceContext tree dominates memory.

### 5. Post-collection pool disposal + streaming analysis (high impact on post-peak)

The ContextPools hold strong references to all pooled contexts. This prevents
GC even after `releaseLinks()` is called on the ReferenceContext tree.

**Approach**: After `collectAll()` completes, dispose of (or nullify) the
ContextPools entirely. Then during analysis, call `releaseLinks()` on
already-analyzed subtrees to allow GC.

**Important caveat**: This does NOT reduce peak memory (which occurs at the end
of collection). It only accelerates memory reclamation during the analysis
phase.

**Caveat on WeakReference alternative**: Using `WeakReference` in pools instead
of destroying them is safe in a two-phase (collect-then-analyze) design, since
the tree holds strong references during collection. However, it would break if
collection and analysis were interleaved — a shared context could be GC'd after
one parent releases it but before the collector links it from another parent.

### 6. Integer keys for `$referencing_contexts` (low-medium impact)

Where `$referencing_contexts` is retained (dynamic children), replace string
keys with integer constants or enum values. PHP optimizes integer-keyed arrays
as packed arrays with lower per-entry overhead (~32 bytes vs ~72 bytes for
string keys).

**Applicability**: Limited to contexts where link names can be mapped to a
finite set of integers. Not applicable to `ArrayElementsContext` where keys
are arbitrary runtime values.

### 7. Eliminate wrapper contexts for array elements / scalar values (high impact, high cost)

`ArrayElementContext` is an empty wrapper (no properties, no MemoryLocation)
created per array element, and `ScalarValueContext` is created per scalar zval.
For a 10,000-element integer array, this produces 20,000 Context objects.

**Possible approaches**:
- Remove `ArrayElementContext` and attach value contexts directly to
  `ArrayElementsContext`.
- Store scalar values as plain PHP values in a native array instead of wrapping
  them in `ScalarValueContext`.
- For associative keys, store key StringContexts in a separate structure.

**Effect**: Could eliminate the majority of Context objects for scalar-heavy
data (config arrays, DB result sets). Scalar arrays would drop from
`O(2n)` contexts to near zero.

**Trade-off**: Breaks the uniform "everything is a ReferenceContext tree"
design. Analysis/traversal code would need special cases for scalar storage,
reducing code clarity. The clean tree structure is a significant
maintainability asset — adding type-specific branching throughout the analyzer
is a real cost.

**Verdict**: Deferred. Consider only after optimizations 1-2 are applied and
profiled. If peak memory is still problematic, this is the next lever, but the
complexity cost is non-trivial.

## Priority

Optimizations 1 and 2 are the strongest candidates: they reduce peak memory,
apply uniformly regardless of target process composition, and are mechanically
straightforward to implement. They also preserve the existing interface and
tree structure.

Optimization 5 is useful as a follow-up for reducing post-peak memory during
analysis. Optimization 7 is the nuclear option — high impact but trades code
clarity for memory savings.
