# Introduction
Reli has a memory profiling mode, and it can be used with the command like below

```bash
reli inspector:memory -p <pid_of_target_process>
```

You can use this mode to analyze the memory usage of the target process, for finding out memory bottlenecks or memory leaks.

For example, you can see statistics such as whether strings, arrays or objects are particularly dominant in a script's memory usage, or contextual information such as where certain local variables in a given call frame are referenced from elsewhere, and the actual values held by certain memory areas.

The functionality of this mode is similar to [php-meminfo](https://github.com/BitOne/php-meminfo), but works in the [phpspy](https://github.com/adsr/phpspy)-ish way.

It captures the memory contents of the target from outside the process, and analyzes it with the knowledge of the internal structures of the PHP VM, then dumps them all. So target programs don't need any modifications, don't need to load a specific extension for this.

# Requirements
- FFI and PCNTL
- PHP 8.1 or above for execution
- PHP 7.0+ for targets
- Only NTS targets are tested currently
- The capability to stab the target process with ptrace (CAP_SYS_PTRACE)
  - Usually running as root is enough

# Installation
## From Composer
```bash
composer create-project reliforp/reli-prof
cd reli-prof
./reli
```

## From Git
```bash
git clone git@github.com:reliforp/reli-prof.git
cd reli-prof
composer install
./reli
```

## From Docker
```bash
docker pull reliforp/reli-prof
docker run -it --security-opt="apparmor=unconfined" --cap-add=SYS_PTRACE --pid=host reliforp/reli-prof
```

# Options
```bash
./reli inspector:memory --help
Description:
  [experimental] get memory usage from an outer process

Usage:
  inspector:memory [options] [--] [<cmd> [<args>...]]

Arguments:
  cmd                                                                command to execute as a target: either pid (via -p/--pid) or cmd must be specified
  args                                                               command line arguments for cmd

Options:
      --stop-process|--no-stop-process                               stop the process while inspecting (default: on)
      --pretty-print|--no-pretty-print                               pretty print the result (default: off)
      --memory-limit-error-file=MEMORY-LIMIT-ERROR-FILE              file path where memory_limit is exceeded
      --memory-limit-error-line=MEMORY-LIMIT-ERROR-LINE              line number where memory_limit is exceeded
      --memory-limit-error-max-depth[=MEMORY-LIMIT-ERROR-MAX-DEPTH]  max attempts to trace back the VM stack on memory_limit error [default: 512]
  -p, --pid=PID                                                      process id
      --php-regex[=PHP-REGEX]                                        regex to find the php binary loaded in the target process
      --libpthread-regex[=LIBPTHREAD-REGEX]                          regex to find the libpthread.so loaded in the target process
      --php-version[=PHP-VERSION]                                    php version (auto|v7[0-4]|v8[01]) of the target (default: auto)
      --php-path[=PHP-PATH]                                          path to the php binary (only needed in tracing chrooted ZTS target)
      --libpthread-path[=LIBPTHREAD-PATH]                            path to the libpthread.so (only needed in tracing chrooted ZTS target)
  -h, --help                                                         Display help for the given command. When no command is given display help for the list command
  -q, --quiet                                                        Do not output any message
  -V, --version                                                      Display this application version
      --ansi|--no-ansi                                               Force (or disable --no-ansi) ANSI output
  -n, --no-interaction                                               Do not ask any interactive question
  -v|vv|vvv, --verbose                                               Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug

```

# Usage
> [!CAUTION]
> **Don't upload the output of this command to the internet, because it can contain sensitive information of the target script!!!**

> [!WARNING]  
> This feature is in an experimental stage and may be less stable than others. The contents of the output may change in the near future.

This tool can be used like this.

```
sudo ./reli i:m --pretty-print -p <pid_of_target_process> >memory_analyzed.json
```

The analysis takes seconds in many case. During the analysis, the target process is stopped by default.

And the output is like below. The target process is [psalm](https://github.com/vimeo/psalm) in this example.


```
{
    "summary": [
        {
            "zend_mm_heap_total": 153092096,
            "zend_mm_heap_usage": 126947784,
            "zend_mm_chunk_total": 153092096,
            "zend_mm_chunk_usage": 126947784,
            "zend_mm_huge_total": 0,
            "zend_mm_huge_usage": 0,
            "vm_stack_total": 262144,
            "vm_stack_usage": 8720,
            "compiler_arena_total": 1900544,
            "compiler_arena_usage": 1791992,
            "possible_allocation_overhead_total": 10196000,
            "possible_array_overhead_total": 21284000,
            "memory_get_usage": 129053816,
            "memory_get_real_usage": 153092096,
            "cached_chunks_size": 0,
            "heap_memory_analyzed_percentage": 98.36809784842008,
            "php_version": "v82",
            "analyzer": "reli 0.9.0"
        }
    ],
    "location_types_summary": {
        "ZendObjectMemoryLocation": {
            "location_count": 124341,
            "memory_usage": 45245784
        },
        "ZendArrayTableOverheadMemoryLocation": {
            "location_count": 108548,
            "memory_usage": 21204736
        },
        "ZendArrayTableMemoryLocation": {
            "location_count": 109462,
            "memory_usage": 17964240
        },
        "ZendStringMemoryLocation": {
            "location_count": 242532,
            "memory_usage": 16149456
        },
<--- snip --->
    "class_objects_summary": {
        "Psalm\\CodeLocation": {
            "count": 32875,
            "total_size": 13413000
        },
        "Psalm\\Type\\Union": {
            "count": 24924,
            "total_size": 12960480
        },
        "Psalm\\Internal\\MethodIdentifier": {
            "count": 8838,
            "total_size": 636336
        },
<--- snip --->
    "context": {
        "call_frames": {
            "#node_id": 0,
            "#type": "CallFramesContext",
            "#count": 4,
            "0": {
                "#node_id": 1,
                "#type": "CallFrameContext",
                "function_name": "Psalm\\IssueBuffer::finish",
                "local_variables": {
                    "#node_id": 2,
                    "#type": "CallFrameVariableTableContext",
                    "project_analyzer": {
                        "#node_id": 3,
                        "#type": "ObjectContext",
                        "#locations": [
                            {
                                "address": 139957204265152,
                                "size": 424,
                                "refcount": 3,
                                "type_info": 3221319688,
                                "class_name": "Psalm\\Internal\\Analyzer\\ProjectAnalyzer"
                            }
                        ],
                        "object_handlers": {
                            "#node_id": 4,
                            "#type": "ObjectHandlersContext",
                            "#locations": [
                                {
                                    "address": 93902816133472,
                                    "size": 200
                                }
                            ]
                        },
                        "object_properties": {
                            "#node_id": 5,
                            "#type": "ObjectPropertiesContext",
                            "#count": 24
                            "config": {
                                "#node_id": 6,
                                "#type": "ObjectContext",
                                "#locations": [
                                    {
                                        "address": 139957201996800,
                                        "size": 1544,
                                        "refcount": 7,
                                        "type_info": 3221239816,
                                        "class_name": "Psalm\\Config"
                                    }
                                ],
                                "object_handlers": {
                                    "#reference_node_id": 4
                                },
<--- snip --->
...
```

## A quick tour of each part of the output
- ZendMM allocates 153,092,096 byte of chunks in total
- 126,947,784 bytes of them are located by this tool
- If the target process calls `memory_get_usage(false)` at this moment, the return value would be 129,053,816
- So 98.36809784842008% of areas reported by `memory_get_usage()` is analyzed
- The kind of the top most memory area is the locations for `zend_object`, which corresponding to the PHP objects in the target process
  - The number of objects is 124,341, and the total analyzed size of them is 45,245,784 bytes
- The class with the most number of instances is `Psalm\CodeLocation`
  - The number of instances is 32,875, and the total analyzed size of them is 13,413,000 bytes
- The "context" field can be used to find out which areas in the script are used in what context. For example, the executing function at this moment is `Psalm\IssueBuffer::finish()`. Its local variables contain `$project_analyzer`, which is an instance of `Psalm\Internal\Analyzer\ProjectAnalyzer`. And you can extract all references to the same instance with `jq`.
```bash
$ cat memory_analized.json | jq 'path(..|objects|select(."#reference_node_id"==3 or ."#node_id"==3))|join(".")'
"context.call_frames.0.local_variables.project_analyzer"
"context.call_frames.1.local_variables.project_analyzer"
"context.class_table.psalm\\internal\\analyzer\\projectanalyzer.static_properties.instance"
"context.objects_store.17"
```

## Useful snippets for analyzing the output
### Extracting the summary
```bash
cat memory_analyzed.json | jq .summary
```

### Extracting top 20 most memory-consuming types of memory locations
```bash
cat memory_analyzed.json | jq .location_types_summary | jq -r '(["location_type", "count", "memory_usage"] | (., map(length*"="))),(to_entries|.[:20]|.[]|[.key,.value.count,.value.memory_usage])|@tsv' | column -t -o ' | '
```

The example of the output is like below.

```bash
location_type                           | count | memory_usage
=============                           | ===== | ============
ZendStringMemoryLocation                | 38045 | 1992633
ZendArrayTableMemoryLocation            | 538   | 991352
ZendOpArrayBodyMemoryLocation           | 1323  | 921840
ZendArrayTableOverheadMemoryLocation    | 416   | 370896
ZendOpArrayHeaderMemoryLocation         | 1356  | 325440
ZendClassEntryMemoryLocation            | 179   | 88784
ZendArgInfosMemoryLocation              | 1228  | 82080
RuntimeCacheMemoryLocation              | 354   | 42976
ZendObjectMemoryLocation                | 273   | 40744
ZendPropertyInfoMemoryLocation          | 371   | 20776
LocalVariableNameTableMemoryLocation    | 1010  | 19696
ZendArrayMemoryLocation                 | 244   | 13664
ObjectsStoreMemoryLocation              | 1     | 8192
DefaultPropertiesTableMemoryLocation    | 106   | 8128
CallFrameVariableTableMemoryLocation    | 22    | 7920
ZendClassConstantMemoryLocation         | 72    | 2880
CallFrameHeaderMemoryLocation           | 26    | 2080
ZendReferenceMemoryLocation             | 45    | 1440
DynamicFuncDefsTableMemoryLocation      | 68    | 736
DefaultStaticMembersTableMemoryLocation | 12    | 416
```

### Extracting top 20 most memory-consuming classes of instances
```bash
cat memory_analyzed.json | jq .class_objects_summary | jq -r '(["class_name", "count", "memory_usage"] | (., map(length*"="))),(to_entries|.[:20]|.[]|[.key,.value.count,.value.memory_usage])|@tsv' | column -t -o ' | '
```

The example of the output is like below.

```bash
class_name                                            | count | memory_usage
==========                                            | ===== | ============
Closure                                               | 62    | 20336
DI\\Definition\\ObjectDefinition                      | 12    | 2016
Fiber                                                 | 5     | 1480
DI\\Definition\\Helper\\AutowireDefinitionHelper      | 10    | 1200
Revolt\\EventLoop\\Internal\\SignalCallback           | 7     | 952
DI\\Definition\\Reference                             | 12    | 864
Amp\\Internal\\FutureState                            | 6     | 816
Amp\\ByteStream\\ReadableResourceStream               | 3     | 696
DI\\Definition\\ObjectDefinition\\MethodInjection     | 9     | 648
Monolog\\Level                                        | 8     | 576
Revolt\\EventLoop\\Driver\\StreamSelectDriver         | 1     | 488
SplQueue                                              | 11    | 440
Revolt\\EventLoop\\Internal\\StreamReadableCallback   | 3     | 408
Amp\\DeferredFuture                                   | 5     | 360
DI\\Definition\\AutowireDefinition                    | 2     | 336
Amp\\Socket\\ResourceSocket                           | 2     | 336
Revolt\\EventLoop\\Internal\\AbstractDriver@anonymous | 1     | 328
Amp\\ByteStream\\WritableResourceStream               | 2     | 304
Generator                                             | 7     | 280
Amp\\Future                                           | 5     | 280
```

### Extracting the references of top 20 largest arrays
```bash
cat memory_analyzed.json | jq '. as $root | path(..|objects|select(."#type"=="ArrayHeaderContext"))| . as $path | $root|getpath($path) as $header | $header.array_elements as $elements | {path: $path|join("."), size: $elements."#locations"[0].size, count: $elements."#count", node_id:$header."#node_id"}' | jq -rs '(["size", "count", "node_id" ,"path"] | (., map(length*"="))),(sort_by(.size) | .[-20:] | reverse | .[] | [.size, .count, .node_id, .path])|@tsv' | column -t -o ' | '
```

The example of the output is like below.

```bash
size   | count | node_id | path
====   | ===== | ======= | ====
319520 | 7937  | 42380   | context.class_table.jetbrains\\phpstormstub\\phpstormstubsmap.constants.CONSTANTS.value
222944 | 4919  | 27618   | context.class_table.jetbrains\\phpstormstub\\phpstormstubsmap.constants.FUNCTIONS.value
203616 | 3917  | 82513   | context.interned_strings
59424  | 1345  | 23577   | context.class_table.jetbrains\\phpstormstub\\phpstormstubsmap.constants.CLASSES.value
54944  | 1205  | 19586   | context.class_table.composerautoloaderinitfa3d2d2c0bbb9c2389347803a32247de.static_properties.loader.object_properties.classMap
1984   | 46    | 19254   | context.class_table.composerautoloaderinitfa3d2d2c0bbb9c2389347803a32247de.static_properties.loader.object_properties.prefixDirsPsr4
1248   | 31    | 23472   | context.class_table.composer\\autoload\\composerstaticinitfa3d2d2c0bbb9c2389347803a32247de.static_properties.files
1248   | 31    | 1845    | context.call_frames.25.symbol_table.array_elements.__composer_autoload_files.value
1216   | 30    | 1754    | context.call_frames.25.symbol_table.array_elements._SERVER.value
800    | 17    | 620     | context.call_frames.7.local_variables.container.object_properties.fetchedDefinitions
768    | 8     | 1726    | context.call_frames.25.symbol_table
608    | 15    | 338     | context.call_frames.7.local_variables.container.object_properties.resolvedEntries
576    | 14    | 471     | context.call_frames.7.local_variables.container.object_properties.definitionSource.object_properties.sources.referenced.array_elements.1.value.object_properties.definitions
544    | 12    | 1394    | context.call_frames.9.this.object_properties.callbacks
512    | 12    | 19215   | context.class_table.composerautoloaderinitfa3d2d2c0bbb9c2389347803a32247de.static_properties.loader.object_properties.prefixLengthsPsr4.array_elements.A.value
512    | 12    | 19053   | context.class_table.composerautoloaderinitfa3d2d2c0bbb9c2389347803a32247de.static_properties.loader.object_properties.prefixLengthsPsr4
480    | 11    | 19066   | context.class_table.composerautoloaderinitfa3d2d2c0bbb9c2389347803a32247de.static_properties.loader.object_properties.prefixLengthsPsr4.array_elements.S.value
320    | 8     | 74667   | context.class_table.reli\\lib\\phpinternals\\opcodes\\opcodefactory.constants.VERSION_MAP.value
320    | 8     | 72734   | context.class_table.monolog\\logger.constants.RFC_5424_LEVELS.value
288    | 7     | 19115   | context.class_table.composerautoloaderinitfa3d2d2c0bbb9c2389347803a32247de.static_properties.loader.object_properties.prefixLengthsPsr4.array_elements.P.value
```

### Extracting the context node of a given context node_id
```bash
cat memory_analyzed.json |  jq '..|objects|select(."#node_id"==1838)'
```

The example of the output is like below.

```bash
{
  "#node_id": 1838,
  "#type": "StringContext",
  "#locations": [
    {
      "address": 140332673364688,
      "size": 36,
      "refcount": 1,
      "type_info": 86,
      "value": "REQUEST_TIME"
    }
  ]
}
```

### Extracting all references to a given context node_id
```bash
cat memory_analized.json | jq 'path(..|objects|select(."#reference_node_id"==3 or ."#node_id"==3))|join(".")'
```

The example of the output is like below.

```
"context.call_frames.0.local_variables.project_analyzer"
"context.call_frames.1.local_variables.project_analyzer"
"context.class_table.psalm\\internal\\analyzer\\projectanalyzer.static_properties.instance"
"context.objects_store.17"
```

### Extracting the call trace
```bash
cat memory_analyzed.json | jq -r '(["frame_no", "function", "line"] | (., map(length*"="))),(path(.context.call_frames[]|objects) as $path | [$path[2], getpath($path).function_name, getpath($path).lineno])|@tsv' | column -t
```

The example of the output is like below.

```bash
frame_no  function                                                         line
========  ========                                                         ====
0         system                                                           4
1         {closure}(/home/sji/work/oss/tmp/pdfparser_test/test.php:11-21)  20
2         Smalot\\PdfParser\\Font::uchr                                    150
3         Smalot\\PdfParser\\Font::loadTranslateTable                      230
4         Smalot\\PdfParser\\Font::init                                    78
5         Smalot\\PdfParser\\Document::init                                90
6         Smalot\\PdfParser\\Document::setObjects                          316
7         Smalot\\PdfParser\\Parser::parseContent                          122
8         Smalot\\PdfParser\\Parser::parseFile                             90
9         <main>
```

### Extracting specific local variables of a given call frame

If the names of variables are `$abc` and `$def`:

```bash
cat memory_analyzed.json | jq '.context.call_frames."1".local_variables|{abc, def}'
```

The example of the output is like below.

```bash
{
  "abc": {
    "#node_id": 142,
    "#type": "ScalarValueContext",
    "#value": 506895433343375
  },
  "def": {
    "#node_id": 143,
    "#type": "ScalarValueContext",
    "#value": 9958150
  }
}
```

## Capturing the memory_limit violation

If you can modify the target script, you can also capture the memory_limit violation via `register_shutdown_function()`, like this.

```php
<?php
ini_set('memory_limit', '2M');

register_shutdown_function(
    function (): void {
        $error = error_get_last();
        if (is_null($error)) {
            return;
        }
        if (strpos($error['message'], 'Allowed memory size of') !== 0) {
            return;
        }
        $pid = getmypid();
        $file_opt = '--memory-limit-error-file=' . escapeshellarg($error['file']);
        $line_opt = '--memory-limit-error-line=' . escapeshellarg((string)$error['line']);
        system("sudo reli i:m -p {$pid} --no-stop-process {$file_opt} {$line_opt} >{$pid}_memory_analyzed.json");
    }
);

function f() {
    $var = array_fill(0, 0x1000, 0);
    f();
}
f();
```

[#384](https://github.com/reliforp/reli-prof/pull/384) explains this a bit more. 

## Memory analysis at the exact timing you want

Reli can analyze the target from outside the process without touching the target code. This in itself is one of the benefits of using Reli, but it also means that you normally cannot choose the exact timing of the analysis, for example what line of the file the target is executing.

If you need to analyze the target at the exact timing you want, you have to touch the target script, like embedding the code which invokes Reli as the previous example for memory_limit violations.

```php
$pid = getmypid();
system("sudo reli i:m -p {$pid} --no-stop-process >{$pid}_memory_analyzed.json");
```

And you can also use [Xdebug](https://xdebug.org/). If the target is stopped at one of the breakpoints you set, then it's a good timing to analyze the target by Reli. This way you don't have to change the target code, though the behavior of the PHP VM isn't exactly same as the production enviornment in this case. Xdebug itself can be used to get the content of variables, but if you use Reli in addition to it, you can also get the statical data of the memory usage or reference graphs. 

## Examples of diagnosing memory issues in the wild
- https://github.com/PHPOffice/PhpSpreadsheet/issues/3814#issuecomment-1862367771
- https://github.com/smalot/pdfparser/issues/631#issuecomment-1847772214

## More detailed explanation of the output
### The `"summary"` field
```bash
cat memory_analyzed.json | jq .summary
```
This section contains the summary of the memory usage of the target process. The fields are:

#### "zend_mm_heap_total" / "zend_mm_heap_usage"
- The total size of the heap allocated by ZendMM (Zend Memory Mnager) and its analyzed usage
- `"zend_mm_chunk_total"` + `"zend_mm_huge_total"` === `"zend_mm_heap_total"`
- `"zend_mm_chunk_usage"` + `"zend_mm_huge_usage"` === `"zend_mm_heap_usage"`

#### "memory_get_usage" / "memory_get_real_usage" / "heap_memory_analyzed_percentage"
- The return value of `memory_get_usage()` and `memory_get_real_usage()` in the target process, if they are called at the time of the analysis
- `"heap_memory_analyzed_percentage"` === `"zend_mm_heap_usage"` / `"memory_get_usage"`
- ZendMM tracks actual heap memory usage and has an aggregated value in the memory, and these fields shows the aggregated value
- Ideally, `"zend_mm_heap_usage"` and `"memory_get_usage"` should be equal, but there are still a lot of areas that the current Reli does not collect, such as the areas used in extensions internally. The smaller the difference between them, the more Reli is likely to know about the memory usage of the target process.
- `"zend_mm_heap_total"` + `"cached_chunks_size"` should be equal to `"memory_get_real_usage"`

#### cached_chunks_size
- This is the total size of the chunks that are cached by ZendMM and not used at the time of the analysis
- ZendMM may not immediately return unused chunks to the OS but use them for another allocation later, and this field represents their total size.

#### zend_mm_chunk_total / zend_mm_chunk_usage
- The total size of the normal chunks allocated by ZendMM and its analyzed usage
- Each normal chunk is 2MB in size

#### zend_mm_huge_total / zend_mm_huge_usage
- The total size of the huge chunks allocated by ZendMM and its analyzed usage
- The huge chunks are used for allocations greater than 2MB in size

#### vm_stack_total / vm_stack_usage
- The total size of the VM stack and its analyzed usage
- The VM stack itself is contained in the heap, and `"vm_stack_total"` is included in the calculation of `"zend_mm_heap_usage"`

#### compiler_arena_total / compiler_arena_usage
- The total size of the compiler arena and its analyzed usage
- The PHP VM uses an arena allocator for compiling things, and the arena is contained in the heap, so `"compiler_arena_total"` is included in the calculation of `"zend_mm_heap_usage"` 
- The locations allocated in the compiler arena are never freed during each request

#### possible_allocation_overhead_total
- ZendMM normally allocates memory space in `chunk`s of 2MB from the OS and divides each `chunk` into 4KB `page`s. Each `page` has a range of allocation sizes that it is responsible for, and for allocations of 3KB or less, each `page` is further divided into fixed-size areas called `bin`s. A `bin` size can be one of 30 different sizes, and the `bin` which can hold the requested size is used for each allocation. If the requested size for the allocation does not exactly match the size of the `bin`, the remaining area is wasted. Also, for allocations that are larger than 3KB and fit in a `chunk`, they are allocated in `page`s. So if the requested size is not a multiple of the `page` size, the remainder is wasted. This field is the sum of such possible wasted areas.
- Note that this does not necessarily mean that this size of area is truly unused; Reli collects various address and size information from the memory of the target process, checks which `bin` or `page` the address is from and accounts for the difference with the size as wasted area. But each `zend_object` corresponding to an instance of a built-in class is often allocated with subsequent areas for internal use, so a `bin` larger than the one corresponding to the `sizeof zend_object` is chosen by ZendMM. As Reli does not yet have much information on built-in classes, such actually used subsequent areas are also included in the calculation of the wasted area.

#### possible_array_overhead_total
- This field is the sum of the possible wasted areas for the `zend_array` structure. The `zend_array` structure is used for the implementation of PHP arrays. Each `zend_array` has a pointer to a table for storing elements. And the table grows as the number of elements increases. When the table grows, the table size is increased by a factor of two to avoid performance degradation due to too many reallocations. So often an area considerably larger than the actual number of elements used is reserved for the table. Reli accounts for the difference between the actual area used in the table and the table size as wasted area.
- The optimizer of the PHP VM may truncate table areas that do not change its size at runtime, such as function tables for classes, to the actual size used in memory. So Reli excludes the seemingly unused table areas from the calculation if they are overlaid by another area, but it is possible that other areas that Reli has not been able to find may be using the "unused" table areas. So the value of this field can be an overestimate.

#### php_version
- The version of the PHP that the target process is using

### The `"location_types_summary"` field
```bash
cat memory_analyzed.json | jq .location_types_summary
```
This section contains the summary of the memory usage of the target process, grouped by the type of the memory area and sorted by total size.

### The `"class_objects_summary"` field
```bash
cat memory_analyzed.json | jq .class_objects_summary
```
This section contains the summary of the objects memory usage of the target process, grouped by the class of the objects and sorted by total size.

Currently, only the sizes of the user-defined classes are calculated correctly.

### The `"context"` field (the context tree)
The `context` field in the output JSON indicates in which context each memory area is referenced.

Reli recursively dumps local variables and `$this` on the call stack from the running call frame to the root of the script invocation, and also dumps global variables and function tables etc... as well as the contents of complex values such as objects or arrays referenced by their zval, in the DFS manner. To avoid infinite recursion on circular references and dump data size explosion, each context is assigned a unique node ID, `#node_id`, and then references to the same output area are output as `"#reference_node_id": 4`. This means that the `contexts` field is effectively represented as a tree with the top-level children described below:

- The call stack
- The global variables table
- The function table
- The class table
- The global constants table
- The interned strings table
- The included files strings table
- The objects_store

#### The representation of each context
First occurrence of each context in the context tree (or graph) are non-referencing nodes, and subsequent occurrences are referencing nodes.

All non-referencing nodes have the following fields:

- The `"#node_id"` field
  - This is a unique ID for each context
  - The ID is assigned in the order of the DFS traversal of the context tree
- The `"#type"` field
  - This field indicates the type of the context
  - The type is represented by a string

All referencing nodes have only the following field:

- The `"#reference_node_id"` field
  - This field indicates the ID of the context that the current context references

According to the type of the context, non-referencing nodes have different fields. The following sections describe the fields of each context type.

##### ScalarValueContext
This context represents a non-string scalar value, i.e. bool, int, float and null. The fields are:

- `"value"`
  - The value of the scalar

##### StringContext
This context represents a string value, and corresponds to `zend_string` structure in the PHP VM. The fields are:

- `"#locations"`
  - The location of the `zend_string` structure
  - The location field contains the size, refcount, and its string value

##### ArrayHeaderContext
This context represents the header of a PHP array, and corresponds to `zend_array` structure in the PHP VM. The fields are:

- `"#locations"`
  - The location of the `zend_array` structure
  - The location field contains the size of the header, refcount
- `"array_elements"`
  - The elements of the array
  - This field represents `ArrayElementsContext` that holds the elements of the array
    - The keys of the array are the keys of the `ArrayElementsContext`
 
Each element of the `ArrayElementsContext` is an `ArrayElementContext`, and has the following fields:

- `"key"`
  - The key of the element
  - This field doesn't exist if the array is not a hash table
- `"value"`
  - The value of the element

##### ObjectContext
This context represents a PHP object, and corresponds to `zend_object` structure in the PHP VM. The fields are:

- `"#locations"`
  - The location of the `zend_object` structure
  - The location field contains the size of the header, refcount, and the class name of the object
- `"object_properties"`
  - The properties of the object
  - This field represents `ObjectPropertiesContext` that holds the properties of the object
    - The names of the properties are the keys of the `ObjectPropertiesContext`

##### PhpReferenceContext
This context represents a PHP reference, and corresponds to `zend_reference` structure in the PHP VM. The fields are:

- `"#locations"`
  - The location of the `zend_reference` structure
  - The location field contains the size, refcount
- `"referenced"`
  - The context of the referenced zval

##### ResourceContext
This context represents a PHP resource, and corresponds to `zend_resource` structure in the PHP VM. The fields are:

- `"#locations"`
  - The location of the `zend_resource` structure
  - The location field contains the header size, refcount

Resources are opaque values that are not accessible from PHP userland, and currently Reli cannot know the contents of the resource. So it is not very useful.

#### The top-level children of the context tree

##### The "call_frames" field
The `call_frames` field represents `CallFramesContext`, that is the call stack at the time of the analysis. Each call frame is represented as `CallFrameContext`, corresponding to the `zend_execute_data` structure in the VM. The first frame is the current executing frame, and the last frame is the root of the call stack. Each `CallFrameContext` may have the following fields:

- `"function_name"`
  - The name of the function called in this call frame
- `"local_variables"`
  - The local variables in this call frame
- `"this"`
  - The `$this` in this call frame
  - Note that calling methods via `$obj->call()` adds refcount by 1, but `$this->call()` doesn't add refcount. So the reference from this field may or may not increase the recount of the object, depending on whether the previous frame refers to the same object in the "this" field.
- `"symbol_table"`
  - The symbol table in this call frame
  - This can be a hash table that holds the local variables, which is used by the engine to implement variable variables or similar features.

Each entry of the `"local_variables"` and `"this"` hold the contents of the corresponding zval.

##### The "global_variable" field
This is the global variables table. The same table is referenced from the `"symbol_table"` field of the root call frame.

##### The "function_table" field
The `"function_table"` field represents `DefinedFunctionsContext`, that is a table of functions defined globally, not belonging to any class. The table holds an array of information corresponding to the `zend_function` structure that represents the PHP function definition. The table key corresponds to the case-insensitive function name in PHP, so the function name in all lowercase letters is used. The `"name"` field of each element in the table holds a reference to the `StringContext` that represents the actual defined name. The first part of the table is followed by elements that represent built-in functions, whose `"#type"` is `"InternalFunctionDefinitionContext"`. After that, there are elements whose `"#type"` is `"UserFunctionDefinitionContext"` that represent functions defined in PHP scripts. Each `"UserFunctionDefinitionContext"` has the following fields:

- `"name"`
  - A reference to the `StringContext` that represents the function name
- `"op_array"`
  - A reference to the `OpArrayContext` that holds the information about compiled VM instructions of the function

The nodes of `OpArrayContext` have fields like the following:

- `"filename"`
  - A reference to the `StringContext` that represents the file name in which the function is defined
- `"doc_comment"`
  - A reference to the `StringContext` that represents the PHPDoc comment of the function
- `"static_variables"`
  - A reference to the `ArrayHeaderContext` that represents the static variables of the function
- `"dynamic_function_definitions"`
  - A reference to the array of `UserFunctionDefinitionContext`. Each context represents another function definition that is defined at runtime in the function, such as closures

##### The "class_table" field
The `"class_table"` field represents `DefinedClassesContext`, that is a table of the defined classes. The table holds an array of `ClassDefinitionContext`, corresponding to the `zend_class_entry` structure that represents the PHP class definition. The table key corresponds to the case-insensitive class name in PHP, so the class name in all lowercase letters is used. The "name" field of each element in the table holds a reference to the `StringContext` that represents the actual defined name. The first part of the table is followed by elements that represent built-in classes, whose `"#is_internal"` is true. After that, there are elements that represent classes defined in PHP scripts. Each `ClassDefinitionContext` has the following fields: 

- `"name"`
  - A reference to the `StringContext` that represents the class name
- `"methods"`
  - A reference to the `DefinedFunctionsContext` that holds the information about the methods of the class
- `"static_properties"`
  - A reference to the `ClassStaticPropertiesContext` that represents the static properties of the class
- `"constants"`
  - A reference to the `ClassConstantsContext` that represents the constants of the class
- `"property_info"`
  - A reference to the `PropertiesInfoContext` that represents the properties of the class
- `"filename"`
  - A reference to the `StringContext` that represents the file name in which the class is defined
- `"doc_comment"`
  - A reference to the `StringContext` that represents the PHPDoc comment of the class

The nodes of `ClassStaticPropertiesContext` have fields for contexts of the current values of the static properties. Each field has the name of the property as the key.

The nodes of `ClassConstantsContext` have fields for contexts of the current values of the class constants. Each field has the name of the constant as the key.

##### The "global_constants" field
The `"global_constants"` field represents the global constants table. The table holds an array of `GlobalConstantContext`, corresponding to the `zend_constant` structure that represents the PHP constant definition. The table key corresponds to the constant name. The first part of the table is filled by elements that represent built-in constants. After that, there are elements that represent constants defined in PHP scripts.

Each `GlobalConstantContext` has the following fields:

- `"name"`
  - A reference to the `StringContext` that represents the constant name
- `"value"`
  - A reference to the context that represents the value of the constant

##### The "interned_strings" field
The `"interned_strings"` field holds an `ArrayHeaderContext`, that represents the interned strings table. The key and the value is always references the same `StringContext` in each element.

Interned strings are deduplicated string in the engine. If a `StringContext` is in the interned strings table, the refcount recorded in its location is always 1.

See also [this article](https://www.phpinternalsbook.com/php7/internal_types/strings/zend_strings.html#interned-strings) for more details.

##### The "included_files" field
The `"included_files"` field holds an `IncludedFilesContext`, that represents the included files table. The table contains an array of `StringContext`, that represents the file names of the all included files.

##### The "objects_store" field

The `objects_store` is an important table that holds references to all objects inside the script, and most references are represented by `"#reference_node_id"` only, as this is the last top-level child outputted. If there is an object in the objects_store that is represented by `"#node_id"` with its contents, it is most likely to be an object whose reference that is held by an internal structure not supported by this tool, such as `\Closure`s passed to `register_shutdown_function()`, or objects whose reference cannot be followed in the normal path due to circular references.

The references in the objects_store don't add refcount to the objects.

# Currently not yet supported
- Variables captured in inactive Generators
- Variables captured in suspended Fibers
- TMP/VARs in PHP 7.0 target
- internal classes other than `\Closure`
- References to `\Closure`s in the engine, such as the callbacks of internal functions like `register_shutdown_function()` or `set_exception_handler()`
- The contents of resources
- Data that can only be reached from circular references that don't contain any objects
- Support for the opcache SHM

# Troubleshooting
## jq says "parse error: Exceeds depth limit for parsing at line 1"
It's not a bug of Reli, but a limitation of `jq`. Currently, `jq` doesn't have a way to increase the limit without recompiling it. You can also try some other tools like `gojq` or `jj` instead.

## Cannot find a way to use this tool in docker
Try this.

```bash
$ docker run -it --security-opt="apparmor=unconfined" --cap-add=SYS_PTRACE --pid=host reliforp/reli-prof i:m -p <pid_of_target_process> >memory_analyzed.json
```

If you hit the memory_limit during the analysis, then you can increase the memory_limit like this.

```bash
$ docker run --entrypoint=php -it --security-opt="apparmor=unconfined" --cap-add=SYS_PTRACE --pid=host reliforp/reli-prof -dmemory_limit=2G reli i:m -p <pid_of_target_process> >memory_analyzed.json
```

# See also
- [PHP Internals Book](https://www.phpinternalsbook.com/)
- [PHP's new hashtable implementation ](https://www.npopov.com/2014/12/22/PHPs-new-hashtable-implementation.html)
- [PHP 7 Virtual Machine](https://www.npopov.com/2017/04/14/PHP-7-Virtual-machine.html) 
- [Internal value representation in PHP 7 - Part 1 ](https://www.npopov.com/2015/05/05/Internal-value-representation-in-PHP-7-part-1.html)
- [Internal value representation in PHP 7 - Part 2 ](https://www.npopov.com/2015/06/19/Internal-value-representation-in-PHP-7-part-2.html)
- https://github.com/adsr/phpspy
- https://github.com/BitOne/php-meminfo
