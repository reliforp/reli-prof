<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Reli\Command\Rmem;

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Rmem\Explore\RmemModel;
use Reli\Rmem\Serve\RmemQueryService;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * MCP (Model Context Protocol) server for .rmem memory snapshots.
 *
 * Runs as a stdio JSON-RPC server, exposing memory analysis tools
 * that any MCP-capable AI agent can discover and use automatically.
 *
 * Two modes:
 *   --rmem=FILE    loads the file in-process (headless, no TUI needed)
 *   --socket=PATH  connects to an existing rmem:serve or explore --serve
 *                  socket (add --control to enable ui.* tools)
 */
final class RmemMcpCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    private const SERVER_NAME = 'reli-rmem';
    private const SERVER_VERSION = '1.0.0';
    private const PROTOCOL_VERSION = '2024-11-05';

    private ?RmemQueryService $service = null;
    /** @var resource|null */
    private mixed $socket = null;
    private bool $controlEnabled = false;
    private ?string $bridgeUrl = null;

    #[\Override]
    public function configure(): void
    {
        $this->setName('rmem:mcp')
            ->setDescription('MCP server for AI-assisted memory analysis of .rmem snapshots')
            ->addOption(
                'rmem',
                null,
                InputOption::VALUE_REQUIRED,
                'path to a .rmem file (loads in-process)',
            )
            ->addOption(
                'socket',
                null,
                InputOption::VALUE_REQUIRED,
                'connect to an existing rmem:serve Unix socket',
            )
            ->addOption(
                'control',
                null,
                InputOption::VALUE_NONE,
                'enable ui.* tools for TUI navigation (requires --socket to a --serve-control explore)',
            )
            ->addOption(
                'bridge',
                null,
                InputOption::VALUE_REQUIRED,
                'base URL of an rmem:live / explore --http-bridge server; enables rmem_navigate to broadcast focus events to browsers',
            )
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $rmemPath */
        $rmemPath = $input->getOption('rmem');
        /** @var string|null $socketPath */
        $socketPath = $input->getOption('socket');
        $this->controlEnabled = (bool) $input->getOption('control');
        /** @var string|null $bridgeUrl */
        $bridgeUrl = $input->getOption('bridge');
        if (is_string($bridgeUrl) && $bridgeUrl !== '') {
            $this->bridgeUrl = rtrim($bridgeUrl, '/');
        }

        if ($rmemPath === null && $socketPath === null) {
            fwrite(STDERR, "Error: specify --rmem=FILE or --socket=PATH\n");
            return 1;
        }

        if (is_string($rmemPath)) {
            if (!$this->loadInProcess($rmemPath)) {
                return 1;
            }
        } elseif (is_string($socketPath)) {
            if (!$this->connectSocket($socketPath)) {
                return 1;
            }
        }

        $this->runStdioLoop();
        return 0;
    }

    private function loadInProcess(string $path): bool
    {
        if (!is_file($path)) {
            fwrite(STDERR, "Error: file not found: {$path}\n");
            return false;
        }

        fwrite(STDERR, "Loading {$path} ...\n");
        $reader = BinaryReader::open($path);
        $substrate = GraphSubstrate::createFromBinary($reader, skipScc: true);
        $model = RmemModel::fromSubstrate($substrate, $reader);
        $reader->clearCastCache();

        $model->ensureLocationInfoLoaded();
        $model->getClassRanking();
        $model->getTypeRanking();
        $model->buildDefinitionIndexes();

        $serverId = bin2hex(random_bytes(6));
        $this->service = new RmemQueryService($model, $path, $serverId);

        fwrite(STDERR, sprintf(
            "Ready: %s nodes, %s edges\n",
            number_format($model->nodeCount),
            number_format($model->edgeCount),
        ));
        return true;
    }

    private function connectSocket(string $path): bool
    {
        $sock = @stream_socket_client("unix://{$path}", $errno, $errstr, 5);
        if ($sock === false) {
            fwrite(STDERR, "Error: cannot connect to {$path}: {$errstr}\n");
            return false;
        }
        $this->socket = $sock;
        fwrite(STDERR, "Connected to {$path}\n");
        return true;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function queryBackend(array $request): array
    {
        if ($this->service !== null) {
            return $this->service->handle($request);
        }

        if ($this->socket !== null) {
            $payload = (string)json_encode($request, JSON_UNESCAPED_UNICODE) . "\n";
            $written = @fwrite($this->socket, $payload);
            if ($written === false || $written === 0) {
                // Reconnect attempt
                return ['ok' => false, 'error' => 'Socket write failed'];
            }
            fflush($this->socket);
            $line = fgets($this->socket);
            if ($line === false || $line === '') {
                return ['ok' => false, 'error' => 'Socket read failed'];
            }
            /** @var mixed $resp */
            $resp = json_decode(trim($line), true);
            /** @var array<string, mixed> */
            return is_array($resp) ? $resp : ['ok' => false, 'error' => 'Invalid response'];
        }

        return ['ok' => false, 'error' => 'No backend'];
    }

    private function runStdioLoop(): void
    {
        stream_set_blocking(STDIN, true);

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                $this->sendResponse($this->makeError(null, -32700, 'Parse error'));
                continue;
            }
            /** @var array<string, mixed> $request */
            $request = $decoded;

            /** @var mixed $id */
            $id = $request['id'] ?? null;
            $method = (string)($request['method'] ?? '');

            $response = match ($method) {
                'initialize' => $this->handleInitialize($id, $request),
                'notifications/initialized' => null, // notification, no response
                'tools/list' => $this->handleToolsList($id),
                'tools/call' => $this->handleToolsCall($id, $request),
                'ping' => $this->makeResult($id, []),
                default => $this->makeError($id, -32601, "Unknown method: {$method}"),
            };

            if ($response !== null) {
                $this->sendResponse($response);
            }
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function handleInitialize(mixed $id, array $request): array
    {
        return $this->makeResult($id, [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => $this->getInstructions(),
        ]);
    }

    /** @return array<string, mixed> */
    private function handleToolsList(mixed $id): array
    {
        return $this->makeResult($id, ['tools' => $this->getToolDefinitions()]);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function handleToolsCall(mixed $id, array $request): array
    {
        /** @var array<string, mixed> $params */
        $params = $request['params'] ?? [];
        $toolName = (string)($params['name'] ?? '');
        /** @var array<string, mixed> $args */
        $args = $params['arguments'] ?? [];

        $mapping = $this->getToolMapping();
        if (!isset($mapping[$toolName])) {
            return $this->makeError($id, -32602, "Unknown tool: {$toolName}");
        }

        $spec = $mapping[$toolName];
        $action = $spec['action'];

        // Check if this is a ui.* action without --control
        if (str_starts_with($action, 'ui.') && !$this->controlEnabled && $this->service !== null) {
            return $this->makeToolResult($id, "Error: ui.* tools require --control mode with --socket", true);
        }

        // bridge.* actions are HTTP, not backend query
        if (str_starts_with($action, 'bridge.')) {
            if ($this->bridgeUrl === null) {
                return $this->makeToolResult($id, 'Error: bridge.* tools require --bridge URL', true);
            }
            $result = $this->callBridge($action, $args);
            $text = (string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $ok = isset($result['ok']) && $result['ok'] === true;
            return $this->makeToolResult($id, $text, !$ok);
        }

        // Build backend request. The values come straight from the
        // JSON-decoded MCP request; carry the mixed through explicitly.
        // The assignment is intentionally mixed-to-mixed (the target's
        // shape declares `array<string, mixed>` too); Psalm flags every
        // mixed-source assignment regardless of target.
        /** @var array<string, mixed> $backendRequest */
        $backendRequest = ['action' => $action];
        /**
         * @var mixed $value
         * @psalm-suppress MixedAssignment $backendRequest is itself
         *   `array<string, mixed>` so propagating the JSON request's
         *   mixed values into a slot of the same widening is intended.
         */
        foreach ($args as $key => $value) {
            $backendRequest[$key] = $value;
        }

        $result = $this->queryBackend($backendRequest);

        // Format as text for MCP
        $text = (string)json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        // RmemQueryService and RmemServeCommand always set $result['ok'] to a
        // literal true/false; use a strict comparison so a non-bool entry
        // (which would only happen if the backend contract is broken) is
        // also treated as an error rather than coerced silently.
        $isError = ($result['ok'] ?? false) !== true;

        return $this->makeToolResult($id, $text, $isError);
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function callBridge(string $action, array $args): array
    {
        if ($this->bridgeUrl === null) {
            return ['ok' => false, 'error' => 'bridge URL not configured'];
        }
        $endpoint = match ($action) {
            'bridge.navigate' => '/api/navigate',
            'bridge.highlight_set' => '/api/highlight_set',
            default => null,
        };
        if ($endpoint === null) {
            return ['ok' => false, 'error' => "unknown bridge action: {$action}"];
        }
        $url = $this->bridgeUrl . $endpoint;
        $body = (string)json_encode($args, JSON_UNESCAPED_UNICODE);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 3,
            ],
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return ['ok' => false, 'error' => "bridge request failed: {$url}"];
        }
        $status = 0;
        /** @var list<string> $headers */
        $headers = $http_response_header ?? [];
        if ($headers !== [] && preg_match('#HTTP/\S+\s+(\d{3})#', $headers[0], $m)) {
            $status = (int)$m[1];
        }
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status];
        }
        return ['ok' => false, 'status' => $status, 'response' => $response];
    }

    /** @param array<string, mixed> $response */
    private function sendResponse(array $response): void
    {
        $json = (string)json_encode($response, JSON_UNESCAPED_UNICODE);
        fwrite(STDOUT, $json . "\n");
        fflush(STDOUT);
    }

    /** @return array<string, mixed> */
    private function makeResult(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function makeError(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** @return array<string, mixed> */
    private function makeToolResult(mixed $id, string $text, bool $isError = false): array
    {
        $result = [
            'content' => [['type' => 'text', 'text' => $text]],
        ];
        if ($isError) {
            $result['isError'] = true;
        }
        return $this->makeResult($id, $result);
    }

    private function getInstructions(): string
    {
        return <<<'INSTRUCTIONS'
reli-prof is a PHP memory profiler. This MCP server provides tools to analyze .rmem memory snapshots.

## What is an .rmem snapshot?

PHP's Zend Engine manages per-request memory through ZendMM (Zend Memory Manager), a pool allocator that tracks all PHP values — objects, arrays, strings, closures, call frames, class definitions, and more. An .rmem snapshot is a graph representation of this memory pool captured at a specific point in time.

reli builds the graph by reading a running (or dumped) PHP process's memory and following pointers from several root entry points: the call stack (executing frames and their local variables), the class table (loaded class definitions), the function table, the global variable table (symbol_table), interned strings, and the object store. Each pointer target becomes a node, and the pointer itself becomes an edge. The result is a dominator tree where every allocated value is reachable from at least one root.

## Node types (reli's context model)

Each node has a **type** that describes what PHP internal structure it represents. These are reli's own names, not PHP's C struct names, but they map 1:1:

| Node type | PHP internal | What it is |
|-----------|-------------|------------|
| `ObjectContext` | `zend_object` | A PHP object instance. The `class` field shows its class name. |
| `ObjectPropertiesContext` | properties table | The property slots of an object (declared + dynamic). |
| `ArrayHeaderContext` | `zend_array` | A PHP array's header (hash table metadata). |
| `ArrayElementsContext` | `Bucket[]` / packed data | The array's element storage (key-value pairs). |
| `ArrayElementContext` | single `Bucket` | One element in an array. `link_name` is the key. |
| `ArrayPossibleOverheadContext` | unused slots | Allocated but unused array capacity (power-of-2 rounding). |
| `StringContext` | `zend_string` | A PHP string. `string_value` shows its content. |
| `CallFramesContext` | — | Container for all call stack frames. |
| (frame entry) | `zend_execute_data` | One call frame. `label` shows `ClassName::method:line`. |
| `CallFrameVariableTableContext` | CV/VAR slots | Local variables and temporaries of a frame. |
| `PhpReferenceContext` | `zend_reference` | A PHP reference (`&$var`). Points to the referenced value. |
| `ClosureContext` | `zend_closure` | A PHP closure / anonymous function. |
| `ResourceContext` | `zend_resource` | A PHP resource (file handle, stream, etc.). |
| `ClassDefinitionContext` | — | A loaded class in the class table. |
| `ClassEntryContext` | `zend_class_entry` | The class entry struct (methods, properties info, etc.). |
| `DefinedFunctionsContext` | — | A function in the function table. |
| `OpArrayContext` | `zend_op_array` | Compiled opcodes for a function/method. |
| `FfiAllocationContext` | native buffer | Memory allocated via PHP FFI (`FFI::new`). |
| `ObjectsStoreContext` | object store | The global object store (all live objects by handle). |
| `ScalarValueContext` | — | A scalar value (int, float, bool, null). Label shows the value. |

## Root branches

`rmem_roots` returns the top-level entry points into the graph:

| Root | What it contains |
|------|-----------------|
| `call_frames` | The call stack — each frame holds local variables, `$this`, arguments. Usually the largest branch. |
| `class_table` | All loaded class definitions — properties info, methods, static variables, constants. |
| `function_table` | All loaded function definitions — op_arrays, static variables. |
| `global_variables` | The `$GLOBALS` symbol table — global-scope variables. |
| `interned_strings` | PHP's interned string table — class names, property names, string literals. |
| `global_constants` | `define()`'d constants. |
| `objects_store` | The object store — every object is registered here. Nodes reachable ONLY via objects_store (not from call_frames or other roots) may be GC candidates. |
| `included_files` | List of included/required files. |

## Key metrics

- **shallow size**: Bytes the node's own struct occupies in ZendMM (e.g., 56 bytes for a `zend_array` header, the string length for a `zend_string`). Does NOT include children.
- **retained size**: Total bytes in the node's dominator subtree — everything that would be freed if this node were garbage-collected. This is the key metric for finding memory bottlenecks.
- **link_name**: The name of the edge — a property name (`$name`), array key (`0`, `key`), variable name (`$var`), or structural role (`object_properties`, `array_elements`, `static_variables`).
- **refcount**: PHP's reference count for the value. High refcount means the value is shared across multiple parents. refcount=0 can mean the value is immutable (interned strings) or managed by a different mechanism.
- **SCC**: Strongly Connected Component — a set of nodes forming a reference cycle. PHP's cycle collector handles these, but large SCCs may indicate design issues or memory leaks.

## Investigation flow

1. **`rmem_roots`** — find which root branch holds the most retained memory. `call_frames` is usually largest (it holds all local variables of the running code).
2. **`rmem_children`** — drill into the heaviest subtree. Follow the largest retained child at each level.
3. **`rmem_sandwich`** — inspect a node in context: who retains it (parents) and what it retains (children). The best single tool for understanding a node.
4. **`rmem_class_ranking`** — which PHP classes consume the most memory? Large counts with small avg size = N+1 allocation. Small counts with huge avg size = fat objects.
5. **`rmem_type_ranking`** — memory by node type. Large `ArrayElementsContext` = array-heavy app. Large `StringContext` = string-heavy.
6. **`rmem_top_retained`** — the biggest retained-size nodes across the snapshot. The top few usually account for most of the heap.
7. **`rmem_search`** — find nodes by class name, function name, or string value.
8. **`rmem_scc_ranking`** — check for reference cycles. Large SCCs that are reachable only from `objects_store` are strong leak candidates.
9. **`rmem_path_to_root`** — trace the ownership chain from any node back to a root. Shows WHY a value is alive.
10. **`rmem_subtree_stats`** — type/class breakdown of a subtree without listing every node. Good for understanding what a large container holds.

## Interpretation patterns

- **retained >> shallow**: The node is a "choke point" — a small object (e.g., 56-byte array header) retaining a large subtree. Releasing or shrinking this one object frees everything below it.
- **Same class appearing thousands of times in `rmem_class_ranking`**: Classic N+1 allocation — the application creates too many instances. Check if they can be streamed, pooled, or reduced.
- **`ArrayPossibleOverheadContext` is large**: PHP arrays over-allocate capacity in powers of 2 (an array with 1025 elements allocates space for 2048). Additionally, arrays never shrink — if elements are `unset`, the capacity stays at the high-water mark. Look for arrays that grew large temporarily and were partially cleared.
- **Nodes reachable only from `objects_store`**: Suspicious but not conclusive. The object store does NOT increment refcount, so compare the node's `refcount` (via `rmem_node_detail`) against the references reli found. If all refcount is accounted for by cycle members (other objects_store-only nodes pointing to each other), the object is alive solely due to a reference cycle — a candidate for PHP's cycle collector. If refcount is higher than the references reli can see, either an untracked structure holds a reference (C extension internals, etc.) or there is a refcount leak bug in the runtime/extension.
- **Large SCC with framework class names** (e.g., Container ↔ Service): Usually a DI container's structural cost — intentional and harmless. Focus on SCCs with application classes.
- **`PhpReferenceContext` in the path**: PHP references (`&$var`) create an extra indirection node. If you see `PhpReferenceContext → ObjectContext`, it means the variable is a reference to the object, not a direct pointer.
- **`static_variables` under a method's `OpArrayContext`**: These are `static $var` declarations inside a function. They persist across calls and can accumulate data.
- **`FfiAllocationContext` nodes**: Memory allocated by PHP FFI (`FFI::new`). By default (`persistent=false`), the buffer is allocated via `emalloc` (ZendMM) and counts toward `memory_get_usage()`. With `persistent=true`, it uses `malloc` outside ZendMM. Either way, the buffer is freed when its parent `FFI\CData` object (the parent `ObjectContext`) is garbage-collected.
INSTRUCTIONS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getToolDefinitions(): array
    {
        $tools = [];
        foreach ($this->getToolMapping() as $name => $spec) {
            if (str_starts_with($spec['action'], 'ui.') && !$this->controlEnabled) {
                continue; // Hide ui tools when not in control mode
            }
            if (str_starts_with($spec['action'], 'bridge.') && $this->bridgeUrl === null) {
                continue; // Hide bridge tools unless --bridge is set
            }
            $tools[] = [
                'name' => $name,
                'description' => $spec['description'],
                'inputSchema' => $spec['inputSchema'],
            ];
        }
        return $tools;
    }

    /**
     * @return array<string, array{action: string, description: string, inputSchema: array<string, mixed>}>
     */
    private function getToolMapping(): array
    {
        return [
            // === Query tools ===
            'rmem_hello' => [
                'action' => 'server.hello',
                'description' => 'Get server info: node count, edge count, file path. Call this first to understand the snapshot size.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            'rmem_roots' => [
                'action' => 'query.roots',
                'description' => 'List root branches of the memory graph (call_frames, class_table, global_variables, etc). START HERE — the branch with the largest retained size is where the main memory usage lives.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 100)'],
                    ],
                ],
            ],
            'rmem_children' => [
                'action' => 'query.children',
                'description' => 'Get children of a node sorted by retained size. Use to drill down from a root branch into the heaviest subtree. Each child shows its retained size — follow the largest one to find bottlenecks.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id' => ['type' => 'integer', 'description' => 'Parent node ID'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 100)'],
                        'sort' => ['type' => 'string', 'enum' => ['retained', 'link_name'], 'description' => 'Sort order (default retained)'],
                        'all_edges' => ['type' => 'boolean', 'description' => 'Include non-tree edges (default true)'],
                    ],
                    'required' => ['node_id'],
                ],
            ],
            'rmem_parents' => [
                'action' => 'query.parents',
                'description' => 'Get parent nodes that retain a given node. Shows WHO is keeping this node alive. Multiple parents means the node is shared.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id' => ['type' => 'integer', 'description' => 'Child node ID'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 100)'],
                    ],
                    'required' => ['node_id'],
                ],
            ],
            'rmem_node_detail' => [
                'action' => 'query.node_detail',
                'description' => 'Get detailed info for a node: type, class, shallow/retained size, address, refcount, string value, SCC membership.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id' => ['type' => 'integer', 'description' => 'Node ID'],
                    ],
                    'required' => ['node_id'],
                ],
            ],
            'rmem_sandwich' => [
                'action' => 'query.sandwich',
                'description' => 'Get parents + node detail + children in one call. The BEST tool for investigating a node — shows the full context: who retains it and what it retains. Use after finding a suspicious node.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id' => ['type' => 'integer', 'description' => 'Node ID to inspect'],
                        'limit' => ['type' => 'integer', 'description' => 'Max parents/children (default 100)'],
                    ],
                    'required' => ['node_id'],
                ],
            ],
            'rmem_path_to_root' => [
                'action' => 'query.path_to_root',
                'description' => 'Trace the ownership chain from a node back to a root branch. Shows the full path of who-retains-who. Useful to understand WHY a node is alive.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id' => ['type' => 'integer', 'description' => 'Node ID'],
                    ],
                    'required' => ['node_id'],
                ],
            ],
            'rmem_class_ranking' => [
                'action' => 'query.class_ranking',
                'description' => 'Rank PHP classes by total shallow memory. Shows count, total bytes, and average size per instance. Great for spotting which class has too many instances or uses too much memory per instance.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 50)'],
                    ],
                ],
            ],
            'rmem_type_ranking' => [
                'action' => 'query.type_ranking',
                'description' => 'Rank node types by total shallow memory (ObjectContext, StringContext, ArrayElementsContext, etc). Shows what kind of data is using the most memory.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 50)'],
                    ],
                ],
            ],
            'rmem_top_retained' => [
                'action' => 'query.top_retained',
                'description' => 'List nodes with the largest retained size across the entire snapshot. These are the biggest memory holders — the top few usually account for most of the heap.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 50)'],
                    ],
                ],
            ],
            'rmem_nodes_by_class' => [
                'action' => 'query.nodes_by_class',
                'description' => 'List all instances of a specific PHP class. Use after rmem_class_ranking to inspect individual instances of a suspicious class.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'class' => ['type' => 'string', 'description' => 'Fully-qualified class name (e.g. App\\Models\\User)'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 100)'],
                    ],
                    'required' => ['class'],
                ],
            ],
            'rmem_nodes_by_type' => [
                'action' => 'query.nodes_by_type',
                'description' => 'List all nodes of a specific type (e.g. StringContext, ArrayHeaderContext). Use after rmem_type_ranking.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'description' => 'Node type name'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 100)'],
                    ],
                    'required' => ['type'],
                ],
            ],
            'rmem_search' => [
                'action' => 'query.search',
                'description' => 'Search across all node labels, class names, and string values. Case-insensitive substring match. Returns nodes sorted by retained size.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'pattern' => ['type' => 'string', 'description' => 'Search string'],
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 100)'],
                    ],
                    'required' => ['pattern'],
                ],
            ],
            'rmem_find_by_address' => [
                'action' => 'query.find_by_address',
                'description' => 'Find a node by its memory address. Accepts hex (0x...) or decimal.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'address' => ['type' => 'string', 'description' => 'Memory address (e.g. 0x7f1234567890)'],
                    ],
                    'required' => ['address'],
                ],
            ],
            'rmem_find_function_def' => [
                'action' => 'query.find_function_def',
                'description' => 'Find the node for a PHP function definition by name.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Function name'],
                    ],
                    'required' => ['name'],
                ],
            ],
            'rmem_find_class_def' => [
                'action' => 'query.find_class_def',
                'description' => 'Find the node for a PHP class definition by name.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Fully-qualified class name'],
                    ],
                    'required' => ['name'],
                ],
            ],
            'rmem_subtree_stats' => [
                'action' => 'query.subtree_stats',
                'description' => 'Walk a subtree and aggregate statistics: type breakdown, class breakdown, total retained. Use to understand WHAT a large node is made of without listing every child.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id' => ['type' => 'integer', 'description' => 'Root node ID for the subtree walk'],
                        'max_nodes' => ['type' => 'integer', 'description' => 'Max nodes to scan (default 100000)'],
                        'max_depth' => ['type' => 'integer', 'description' => 'Max depth (default 50)'],
                    ],
                    'required' => ['node_id'],
                ],
            ],
            'rmem_scc_for_node' => [
                'action' => 'query.scc_for_node',
                'description' => 'Get the reference cycle (SCC) that a node belongs to, including all members and their intra-cycle edges. Shows the cycle structure for debugging circular references.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id' => ['type' => 'integer', 'description' => 'Node ID'],
                    ],
                    'required' => ['node_id'],
                ],
            ],
            'rmem_scc_ranking' => [
                'action' => 'query.scc_ranking',
                'description' => 'Rank reference cycles (SCCs) by total size. Large SCCs may indicate memory leaks caused by circular references that prevent garbage collection.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max results (default 50)'],
                    ],
                ],
            ],

            // === UI tools (--control only) ===
            'rmem_navigate' => [
                'action' => 'ui.navigate_sandwich',
                'description' => 'Navigate the TUI to show the sandwich view for a specific node. The human user will see the node\'s parents and children on their screen. Use this to guide the user to interesting findings.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id' => ['type' => 'integer', 'description' => 'Node ID to navigate to'],
                        'if_revision' => ['type' => 'integer', 'description' => 'Only navigate if TUI is at this revision (prevents stale navigation)'],
                    ],
                    'required' => ['node_id'],
                ],
            ],
            'rmem_navigate_roots' => [
                'action' => 'ui.navigate_roots',
                'description' => 'Navigate the TUI back to the root branches view.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            'rmem_navigate_back' => [
                'action' => 'ui.navigate_back',
                'description' => 'Go back one step in the TUI navigation history (same as pressing Backspace).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            'rmem_navigate_class_ranking' => [
                'action' => 'ui.navigate_class_ranking',
                'description' => 'Switch the TUI to the class ranking view (same as pressing c).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            'rmem_navigate_type_ranking' => [
                'action' => 'ui.navigate_type_ranking',
                'description' => 'Switch the TUI to the type ranking view (same as pressing y).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            'rmem_navigate_top_retained' => [
                'action' => 'ui.navigate_top_retained',
                'description' => 'Switch the TUI to the top retained size ranking (same as pressing s).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            'rmem_get_focus' => [
                'action' => 'ui.get_current_focus',
                'description' => 'Get what the TUI is currently showing: the view mode (list/sandwich) and focused node. Use to understand what the user is looking at before responding.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            'rmem_get_selection' => [
                'action' => 'ui.get_current_selection',
                'description' => 'Get the node currently selected by the user\'s cursor in the TUI. Returns node_id, label, retained/shallow size, and position in the list.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],

            // === Bridge tool (--bridge only) ===
            'rmem_broadcast_focus' => [
                'action' => 'bridge.navigate',
                'description' => 'Broadcast a focus_node event to every browser connected to the HTTP bridge. Use to highlight a node in all open viz tabs so the human user can see what you are investigating. If the bridge is attached to an explore TUI (--http-bridge), the TUI will also jump to the node.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_id' => ['type' => 'integer', 'description' => 'Node ID to highlight across browsers (and TUI when attached)'],
                    ],
                    'required' => ['node_id'],
                ],
            ],
            'rmem_highlight_set' => [
                'action' => 'bridge.highlight_set',
                'description' => 'Highlight a SET of nodes simultaneously across every connected browser (e.g. the members of an SCC, a suspicious subtree, or the path from a root to a leak). Non-members are dimmed in the 3D force view and rings in Pack / Treemap / Sunburst. Passing an empty array clears the set.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'node_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer'],
                            'description' => 'Node IDs to highlight together. Empty array clears.',
                        ],
                    ],
                    'required' => ['node_ids'],
                ],
            ],
        ];
    }
}
