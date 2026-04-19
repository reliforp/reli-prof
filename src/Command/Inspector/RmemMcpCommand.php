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

namespace Reli\Command\Inspector;

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Rmem\Explore\RmemModel;
use Reli\Rmem\Serve\RmemQueryService;
use Symfony\Component\Console\Command\Command;
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
final class RmemMcpCommand extends Command
{
    private const SERVER_NAME = 'reli-rmem';
    private const SERVER_VERSION = '1.0.0';
    private const PROTOCOL_VERSION = '2024-11-05';

    private ?RmemQueryService $service = null;
    /** @var resource|null */
    private mixed $socket = null;
    private bool $controlEnabled = false;

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:rmem:mcp')
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
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $rmemPath = $input->getOption('rmem');
        $socketPath = $input->getOption('socket');
        $this->controlEnabled = (bool) $input->getOption('control');

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
            $payload = json_encode($request, JSON_UNESCAPED_UNICODE) . "\n";
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
            $resp = json_decode(trim($line), true);
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
            $request = json_decode($line, true);
            if (!is_array($request)) {
                $this->sendResponse($this->makeError(null, -32700, 'Parse error'));
                continue;
            }

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

        // Build backend request
        $backendRequest = ['action' => $action];
        foreach ($args as $key => $value) {
            $backendRequest[$key] = $value;
        }

        $result = $this->queryBackend($backendRequest);

        // Format as text for MCP
        $text = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $isError = !($result['ok'] ?? false);

        return $this->makeToolResult($id, $text, $isError);
    }

    /** @param array<string, mixed> $response */
    private function sendResponse(array $response): void
    {
        $json = json_encode($response, JSON_UNESCAPED_UNICODE);
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
reli-prof is a PHP memory profiler. This MCP server provides tools to analyze .rmem memory snapshots — binary dumps of a PHP process's heap at a point in time.

## Key concepts

- **node**: A memory entity — could be an object, array, string, call frame, closure, class definition, etc.
- **node_id**: Unique integer identifying a node in the snapshot. Use this to drill into specific nodes.
- **shallow size**: Bytes the node itself occupies (its struct/header, not counting children).
- **retained size**: Total bytes that would be freed if this node were garbage-collected — includes the entire subtree reachable only through this node. This is the key metric for finding memory bottlenecks.
- **tree edge**: Ownership edge in the dominator tree. Parent "retains" child — if parent is freed, child is freed.
- **link_name**: The name of the reference (property name, array key, variable name, etc).
- **SCC**: Strongly Connected Component — a group of nodes in a reference cycle. Relevant for detecting memory leaks.
- **sandwich view**: Shows a node's parents (who retains it) and children (what it retains) simultaneously — the most useful view for investigation.

## Investigation flow

1. **Start with `rmem_roots`** — see top-level branches (call_frames, class_table, etc). The largest branch is usually where to dig.
2. **Drill down with `rmem_children`** — follow the largest retained subtree.
3. **Use `rmem_sandwich`** when you find a suspicious node — see who retains it (parents) and what it retains (children) in one call.
4. **`rmem_class_ranking`** — which PHP classes use the most memory? Good for spotting N+1 allocation patterns.
5. **`rmem_type_ranking`** — memory by node type (array, string, object, etc).
6. **`rmem_top_retained`** — largest retained-size nodes across the entire snapshot.
7. **`rmem_search`** — find nodes by class name, function name, or string value.
8. **`rmem_scc_ranking`** — check for reference cycles. Large SCCs may indicate memory leaks.
9. **`rmem_path_to_root`** — trace the ownership chain from any node back to a root branch.

## Tips

- retained >> shallow means the node is a "choke point" — small object holding a large subtree.
- Objects with high refcount (shown in rmem_node_detail) are shared across multiple parents.
- Array overhead (capacity vs used slots) shows up in rmem_subtree_stats type_breakdown as ArrayPossibleOverheadContext.
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
        ];
    }
}
