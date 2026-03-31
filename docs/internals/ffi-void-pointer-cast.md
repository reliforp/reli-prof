# FFI void* Cast SEGV

## The Problem

`FFI::cast('long', $cdata)` on a `void*` CData causes a **segmentation fault**.
This happens because PHP's FFI internally dereferences the pointer during the
cast operation. When the `void*` holds a remote process address (read via
`process_vm_readv`), that address is not valid in the current process's address
space, resulting in SEGV.

Other pointer types (`char*`, `zval*`, etc.) do not exhibit this behavior —
FFI handles them by reading the pointer value directly without dereferencing.

## How It Manifests

```php
// Reading a struct with a void* field from a remote process
$ffi = FFI::load('headers/v81.h');
$buf = $memory_reader->read($pid, $address, $size);
$casted = $ffi->cast('zend_fiber_stack', $buf);

// This works — just accessing the CData field
$p = $casted->pointer;  // $p is a void* CData

// This SEGVs — FFI tries to dereference the void*
$val = FFI::cast('long', $p);  // SEGV!

// Cast::castPointerToInt() calls FFI::cast('long', ...) internally,
// so it has the same problem with void*
$val = Cast::castPointerToInt($p);  // SEGV!
```

## The Workaround

Declare the field as `uintptr_t` instead of `void*` in the C header copy.
The memory layout is identical (both are pointer-sized integers), but FFI
treats `uintptr_t` as a plain integer and does not attempt to dereference it.

```c
// Before (SEGVs when cast to long)
struct _zend_fiber_stack {
    void *pointer;
    size_t size;
};

// After (safe to read as integer)
struct _zend_fiber_stack {
    uintptr_t pointer;
    size_t size;
};
```

## Existing Usage

This pattern is already used in several places in the codebase:

- `zend_mm_huge_list.ptr`: `void*` → `intptr_t` (all versions)
- `zend_internal_function_info.required_num_args`: `zend_uintptr_t` (all versions)
- `zend_fiber_stack.pointer`: `void*` → `uintptr_t` (v81)

## Rules of Thumb

- Never pass a `void*` CData to `Cast::castPointerToInt()` or `FFI::cast('long', ...)`
- When adding a struct with `void*` fields to the headers, replace them with
  `uintptr_t` (or `intptr_t` for signed) if you need to read the address value
- This only affects fields you read as integers; `void*` fields that are only
  used as opaque pointers for `Pointer::fromCData()` with typed targets are fine
  (e.g. `zend_fiber_context.handle` is `void*` but we never cast it to an integer)
