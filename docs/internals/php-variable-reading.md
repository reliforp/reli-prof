# Reading PHP Variables from Process Memory

## Overview

reli-prof reads PHP variable values from a target process without
injecting code or loading extensions. It uses `process_vm_readv(2)`
to read the target's memory and interprets PHP internal structures.

## PHP Internal Structures for Variables

### Global Variables: `EG->symbol_table`

`executor_globals.symbol_table` is a `zend_array` (hash table) that
maps variable names to zvals. Only populated when:
- `$GLOBALS['name'] = value` is used
- `extract()` is called
- Variable variables (`$$name`) are used

In PHP 8.1+, `$GLOBALS` entries may be `IS_INDIRECT` zvals pointing
to CV (compiled variable) slots in the main frame.

**Access path:**
```
EG→symbol_table (ZendArray) →findByKey('name') → Bucket→val (Zval)
```

### Local Variables: Call Frame CVs

PHP compiled variables (CVs) are stored immediately after the
`zend_execute_data` structure in memory. Variable names come from
`zend_op_array->vars` (an array of `zend_string*`).

**Access path:**
```
EG→current_execute_data (ZendExecuteData)
  →getVariables(dereferencer, type_reader)
  yields: name (string) → Zval
```

For watching a specific function's locals, walk `prev_execute_data`
chain and match function name via `ZendFunction::getFullyQualifiedFunctionName()`.

### Class Static Properties: `ZendClassEntry->static_members_table`

Static properties are stored in a table indexed by property offset.
The table address may need `map_ptr_base` resolution (from CG).

**Access path:**
```
EG→class_table (ZendArray)
  →findByKey(strtolower(class_name)) → Bucket→val→ce (ZendClassEntry)
  →getStaticPropertyIterator(dereferencer, type_reader, map_ptr_base)
  yields: name (string) → Zval
```

### Function Static Variables: `ZendOpArray->static_variables`

Function-level `static $var` values are stored in `op_array->static_variables`,
a `zend_array` keyed by variable name.

**Access path:**
```
EG→function_table (ZendArray)
  →findByKey(strtolower(func_name)) → Bucket→val→func (ZendFunction)
  →op_array→static_variables (ZendArray)
  →findByKey('var_name') → Bucket→val (Zval)
```

## Zval Type Handling

| PHP Type | zval type tag | How to read value |
|----------|--------------|-------------------|
| int | IS_LONG | `value.lval` |
| float | IS_DOUBLE | `value.dval` |
| string | IS_STRING | `value.str` → deref ZendString → `toString()` |
| array | IS_ARRAY | `value.arr` → deref ZendArray → `nNumOfElements` |
| bool | IS_TRUE/IS_FALSE | type tag itself |
| null | IS_NULL | type tag itself |
| object | IS_OBJECT | `value.obj` → deref ZendObject → `getPropertiesIterator()` |
| reference | IS_REFERENCE | `value.ref` → deref ZendReference → `ref.val` (recurse) |
| indirect | IS_INDIRECT | `value.zv` → deref Zval at pointer (recurse) |

## Nested Access (Path Expressions)

`VariableReader::resolvePath()` traverses a chain of zvals:

```
$config[db][host]  →  findByKey('db') on array → findByKey('host') on sub-array
$obj->items[0]     →  getPropertiesIterator() → findByKey('0') on array
```

### Critical: CData Lifetime

After `findByKey()` or property iteration, the returned Zval is a CData
view into the parent Bucket/ZvalArray memory. **Must re-deref from Pointer**
before the parent goes out of scope:

```php
$bucket = $arr->findByKey($dereferencer, $key);
$zval = $dereferencer->deref($bucket->val->getPointer());  // independent copy
```

See `docs/internals/ffi-cdata-lifetime.md` for details.

## IS_INDIRECT in $GLOBALS (PHP 8.1+)

Before PHP 8.1, `$GLOBALS` was a regular array. From PHP 8.1, `$GLOBALS`
was changed to use `IS_INDIRECT` zvals that point to CV slots:

```
$GLOBALS['x'] = 42;
// symbol_table['x'] → IS_INDIRECT → CV slot → IS_LONG(42)
```

`VariableReader::resolveIndirectAndRef()` handles this by following the
pointer chain before accessing the actual value.

## Function Static Variables: Template vs Runtime (PHP 7.4+)

`zend_op_array` has two fields for static variables:
- `static_variables` (`HashTable*`): **template** with initial values
- `static_variables_ptr__ptr` (`HashTable**`): **ZEND_MAP_PTR** to
  runtime copy (PHP 7.4+)

When a function is first called, PHP copies `static_variables` and
stores the pointer via `ZEND_MAP_PTR_SET`. Subsequent calls modify
the runtime copy. The template stays unchanged.

```
static $count = 0;  // template: count=0
$count++;           // runtime copy: count=1, 2, 3, ...
```

To read the runtime value:
```php
$map_ptr_raw = $op_array->static_variables_ptr;
$resolved = $zend_type_reader->resolveMapPtr(
    $cg->map_ptr_base, $map_ptr_raw, $dereferencer
);
$runtime_array = $dereferencer->deref(new Pointer(
    ZendArray::class, $resolved, sizeOf('HashTable')
));
```

**Note:** Runtime static vars entries are `IS_REFERENCE` wrapping
the actual value. Must follow the reference to get the value.

**Known issue:** `MemoryLocationsCollector` (line ~1295) reads
`op_array->static_variables` (template) instead of resolving the
MAP_PTR. This means memory analysis reports show initial values,
not runtime values, for function static variables.

## Version Differences

| Feature | PHP 7.x | PHP 8.0 | PHP 8.1+ |
|---------|---------|---------|----------|
| $GLOBALS symbol_table | Regular array | Regular array | IS_INDIRECT to CVs |
| IS_INDIRECT type tag | 15 (7.0), 13 (7.3-7.4) | 12 | 12 |
| Typed properties | N/A | Exists | Exists |
| Property info table | Simple | With types | With types + readonly |

The `ZendTypeReader` version-specific headers handle these differences.
