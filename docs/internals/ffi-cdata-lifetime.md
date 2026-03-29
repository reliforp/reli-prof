# FFI CData Lifetime Pitfalls

## The Problem

When reading PHP process internals via FFI, `CData` objects created by
`FFI::cast()` are **views** into a parent buffer, not independent copies.
If the parent PHP object is garbage-collected, the view becomes a dangling
pointer — reads return garbage or cause EFAULT (errno 14).

## How It Manifests in reli-prof

### Bucket → Zval view

`ZendArray::findByKey()` returns a `Bucket` object. `Bucket->val` creates
a `Zval` via `FFI::cast()` over the Bucket's CData memory:

```php
// Bucket::__get('val')
'val' => $this->val = new Zval(
    new CastedCData(
        $this->casted_cdata->casted->val,  // view into Bucket memory
        $this->casted_cdata->casted->val,
    ),
    new Pointer(Zval::class, ...)
)
```

If you do:

```php
$bucket = $arr->findByKey($dereferencer, 'key');
$zval = $bucket->val;
// ... later, $bucket goes out of scope or is reassigned ...
// $zval->value->str is now a dangling pointer!
$dereferencer->deref($zval->value->str);  // EFAULT
```

### ZvalArray → Zval view

Similarly, `ZvalArray[$index]` returns a Zval backed by the ZvalArray's
CData. This affects:
- `ZendObject::getPropertiesIterator()` — iterates ZvalArray
- `ZendExecuteData::getVariables()` — iterates CV ZvalArray
- `ZendClassEntry::getStaticPropertyIterator()` — iterates ZvalArray

## The Fix Pattern

After obtaining a Zval from a parent structure, **re-deref it from its
Pointer** to get an independent CData copy:

```php
// BAD: $zval is a view into $bucket's CData
$bucket = $arr->findByKey($dereferencer, $key);
$current = $bucket->val;

// GOOD: $current has its own CData buffer
$bucket = $arr->findByKey($dereferencer, $key);
$current = $dereferencer->deref($bucket->val->getPointer());
```

This pattern costs one extra `process_vm_readv` call (16 bytes for a zval)
but guarantees the CData is independently owned.

### Where This Is Applied

- `VariableReader::resolvePath()` — after `findByKey()` for array keys
- `VariableReader::resolvePath()` — after `getPropertiesIterator()` for
  object properties

### Where This Might Be Needed

Any code that:
1. Obtains a `CDataDereferencable` from a parent structure's field
2. Lets the parent go out of scope
3. Later accesses the child's CData fields

Grep for patterns like:
```
$var = $parent->field;
// ... parent may be GC'd ...
$var->nested_field  // potential dangling CData
```

## IS_INDIRECT Handling

Related: PHP's `IS_INDIRECT` zval type (used by `$GLOBALS` entries in
PHP 8.1+ to point to CV slots) requires following the indirection before
accessing the value. The existing pattern from `MemoryLocationsCollector`:

```php
if ($zval->isIndirect()) {
    $zval = $dereferencer->deref(
        $zval->value->getAsPointer(
            Zval::class,
            $zend_type_reader->sizeOf('zval'),
        )
    );
}
```

`VariableReader::resolveIndirectAndRef()` handles both `IS_INDIRECT` and
`IS_REFERENCE` in a unified loop.

## Future: Systemic Fix

The current re-deref pattern works but is opt-in — every call site
must remember to do it. Potential systemic approaches:

### 1. CastedCData Parent Retention

`CastedCData` already holds a reference to the raw buffer alongside
the cast overlay. Extending this so that child CData objects (created
from parent struct fields) retain a reference to the parent's
`CastedCData` would prevent the parent from being GC'd while any
child is alive. This is partially what `CastedCData` was designed
for, but the Bucket→Zval path bypasses it because `Zval` is
constructed inline from `$this->casted_cdata->casted->val`.

### 2. WeakMap-Based Dependency Tracking

Use `WeakMap<object, list<CData>>` in the dereferencer to track
which deref results depend on which buffers. When a child view is
created, register the parent buffer as a dependency. The WeakMap
would prevent the parent from being collected while children exist.

**Concern:** `WeakMap` works on object keys (key GC'd → entry removed),
which is the opposite of what we want (we want "keep parent alive
while child exists"). Would need `SplObjectStorage` or an explicit
retain list instead. WeakMap is also not extensively battle-tested
in PHP FFI contexts — unclear if it correctly interacts with CData
ref counting.

### 3. Dereferencer Read Cache

Simpler alternative: the `RemoteProcessDereferencer` keeps an LRU
cache of recent deref results (e.g., last 32 entries keyed by
Pointer address). This naturally keeps parent CData alive for the
duration of a multi-step traversal. Downside: cache invalidation
if the target process mutates memory between reads.

### Current Status

The re-deref pattern is used in `VariableReader::resolvePath()`.
Other traversal code (e.g., `MemoryLocationsCollector`) may have
similar latent issues that haven't manifested because the traversal
patterns happen to keep parent objects alive long enough. A systemic
audit is recommended.

## References

- PHP FFI docs: https://www.php.net/manual/en/book.ffi.php
- `FFI::cast()` creates a view, not a copy
- `process_vm_readv(2)` — the underlying syscall for reading target memory
- `src/Lib/Process/Pointer/RemoteProcessDereferencer.php` — the deref impl
