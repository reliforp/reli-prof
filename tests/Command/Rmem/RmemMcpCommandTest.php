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

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Output\MemoryOutput\BinaryMemoryOutput;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\Process\MemoryLocation;

/**
 * Tests the MCP server's JSON-RPC protocol handling by spawning
 * the command as a subprocess and feeding JSON-RPC lines via stdin.
 */
class RmemMcpCommandTest extends TestCase
{
    private string $rmem_path = '';
    private string $project_root;

    #[\Override]
    protected function setUp(): void
    {
        $this->project_root = dirname(__DIR__, 3);

        $path = tempnam(sys_get_temp_dir(), 'reli_mcp_test_');
        self::assertNotFalse($path);
        $this->rmem_path = $path . '.rmem';

        $this->buildFixture();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (file_exists($this->rmem_path)) {
            @unlink($this->rmem_path);
        }
    }

    private function buildFixture(): void
    {
        $sink = new BinaryContextTreeSink(batch_size: 10);

        // Node IDs must be 0-based contiguous to match the on-disk CSR
        // index scheme used by buildCsrSections in BinaryMemoryOutput.
        $loc1 = new ZendObjectMemoryLocation(0x1000, 100, 1, 7, 'App\\RootClass');
        $sink->emitNode(0, null, 'root_branch', 'RootContext', [$loc1], []);

        $loc2 = new ZendObjectMemoryLocation(0x2000, 200, 1, 7, 'App\\ChildClass');
        $sink->emitNode(1, 0, 'child_a', 'ObjectContext', [$loc2], []);

        $loc3 = new ZendStringMemoryLocation(0x3000, 48, 2, 6, 'hello world');
        $sink->emitNode(2, 1, 'name', 'StringContext', [$loc3], []);

        $loc4 = new MemoryLocation(0x4000, 64);
        $sink->emitNode(3, 0, 'child_b', 'LeafContext', [$loc4], []);

        $binary_output = new BinaryMemoryOutput($this->rmem_path);
        $binary_output->finalizeStreaming($sink, [
            ['zend_mm_heap_usage' => '412', 'php_version' => '8.2.0'],
        ]);
    }

    /**
     * Open the MCP server subprocess, send initialize, then run a callback.
     *
     * @param callable(resource, resource, resource): void $callback
     *     Receives ($stdin, $stdout, $stderr) pipes.
     * @param list<string> $extraArgs
     */
    private function withMcpProcess(callable $callback, array $extraArgs = []): void
    {
        $cmd = array_merge(
            [PHP_BINARY, $this->project_root . '/reli', 'rmem:mcp', '--rmem=' . $this->rmem_path],
            $extraArgs,
        );
        $descriptors = [
            0 => ['pipe', 'r'], // stdin: child reads, parent writes
            1 => ['pipe', 'w'], // stdout: child writes, parent reads
            2 => ['pipe', 'w'], // stderr: child writes, parent reads
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $this->project_root);
        self::assertIsResource($proc);

        stream_set_timeout($pipes[1], 30);
        stream_set_timeout($pipes[2], 5);

        // Wait for the server to finish loading the .rmem file.
        // It prints "Ready: ..." to stderr when done.
        $ready = false;
        while (($stderrLine = fgets($pipes[2])) !== false) {
            if (str_contains($stderrLine, 'Ready:')) {
                $ready = true;
                break;
            }
        }
        if (!$ready) {
            // Drain remaining stderr for diagnostics
            $stderr_rest = stream_get_contents($pipes[2]);
            @fclose($pipes[0]);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            proc_terminate($proc);
            proc_close($proc);
            self::fail('MCP server did not become ready. stderr: ' . ($stderr_rest ?: '(empty)'));
        }

        try {
            $callback($pipes[0], $pipes[1], $pipes[2]);
        } finally {
            @fclose($pipes[0]);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /**
     * Send a JSON-RPC request and read the response.
     *
     * @param resource $stdin
     * @param resource $stdout
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function sendRequest($stdin, $stdout, array $request): array
    {
        $line = json_encode($request, JSON_UNESCAPED_UNICODE);
        self::assertIsString($line);
        fwrite($stdin, $line . "\n");
        fflush($stdin);

        $response = fgets($stdout);
        self::assertIsString($response, 'Expected a response line from MCP server');
        self::assertNotSame('', trim($response), 'Response must not be empty');

        $decoded = json_decode(trim($response), true);
        self::assertIsArray($decoded, 'Response must be valid JSON');

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * Send the MCP initialize handshake.
     *
     * @param resource $stdin
     * @param resource $stdout
     * @return array<string, mixed>
     */
    private function sendInitialize($stdin, $stdout): array
    {
        return $this->sendRequest($stdin, $stdout, [
            'jsonrpc' => '2.0',
            'id' => 0,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'test', 'version' => '0.0.1'],
            ],
        ]);
    }

    public function testInitializeHandshake(): void
    {
        $this->withMcpProcess(function ($stdin, $stdout, $stderr): void {
            $response = $this->sendInitialize($stdin, $stdout);

            $this->assertSame('2.0', $response['jsonrpc']);
            $this->assertSame(0, $response['id']);
            $this->assertArrayHasKey('result', $response);

            $result = $response['result'];
            $this->assertSame('2024-11-05', $result['protocolVersion']);
            $this->assertSame('reli-rmem', $result['serverInfo']['name']);
            $this->assertSame('1.0.0', $result['serverInfo']['version']);
            $this->assertArrayHasKey('capabilities', $result);
            $this->assertArrayHasKey('instructions', $result);
        });
    }

    public function testToolsList(): void
    {
        $this->withMcpProcess(function ($stdin, $stdout, $stderr): void {
            $this->sendInitialize($stdin, $stdout);

            $response = $this->sendRequest($stdin, $stdout, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => new \stdClass(),
            ]);

            $this->assertSame(1, $response['id']);
            $this->assertArrayHasKey('result', $response);

            $tools = $response['result']['tools'];
            $this->assertIsArray($tools);

            $toolNames = array_column($tools, 'name');

            // Key query tools must be present
            $this->assertContains('rmem_hello', $toolNames);
            $this->assertContains('rmem_roots', $toolNames);
            $this->assertContains('rmem_children', $toolNames);
            $this->assertContains('rmem_parents', $toolNames);
            $this->assertContains('rmem_sandwich', $toolNames);
            $this->assertContains('rmem_class_ranking', $toolNames);
            $this->assertContains('rmem_search', $toolNames);

            // UI tools must NOT be present without --control
            $this->assertNotContains('rmem_navigate', $toolNames);
            $this->assertNotContains('rmem_get_focus', $toolNames);

            // Each tool must have description and inputSchema
            foreach ($tools as $tool) {
                $this->assertArrayHasKey('description', $tool);
                $this->assertArrayHasKey('inputSchema', $tool);
            }
        });
    }

    public function testToolsCallHello(): void
    {
        $this->withMcpProcess(function ($stdin, $stdout, $stderr): void {
            $this->sendInitialize($stdin, $stdout);

            $response = $this->sendRequest($stdin, $stdout, [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'rmem_hello',
                    'arguments' => new \stdClass(),
                ],
            ]);

            $this->assertSame(2, $response['id']);
            $this->assertArrayHasKey('result', $response);

            $content = $response['result']['content'];
            $this->assertIsArray($content);
            $this->assertSame('text', $content[0]['type']);

            // The text contains the backend JSON response
            $backendResult = json_decode($content[0]['text'], true);
            $this->assertIsArray($backendResult);
            $this->assertTrue($backendResult['ok']);
            // 4 real nodes + 1 sentinel in the substrate
            $this->assertSame(5, $backendResult['data']['node_count']);
            $this->assertSame(4, $backendResult['data']['edge_count']);
        });
    }

    public function testToolsCallRoots(): void
    {
        $this->withMcpProcess(function ($stdin, $stdout, $stderr): void {
            $this->sendInitialize($stdin, $stdout);

            $response = $this->sendRequest($stdin, $stdout, [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'rmem_roots',
                    'arguments' => new \stdClass(),
                ],
            ]);

            $this->assertSame(3, $response['id']);
            $this->assertArrayHasKey('result', $response);

            $content = $response['result']['content'];
            $backendResult = json_decode($content[0]['text'], true);
            $this->assertIsArray($backendResult);
            $this->assertTrue($backendResult['ok']);
            $this->assertArrayHasKey('data', $backendResult);
            $this->assertIsArray($backendResult['data']);
            $this->assertNotEmpty($backendResult['data']);
        });
    }

    public function testToolsCallUnknownTool(): void
    {
        $this->withMcpProcess(function ($stdin, $stdout, $stderr): void {
            $this->sendInitialize($stdin, $stdout);

            $response = $this->sendRequest($stdin, $stdout, [
                'jsonrpc' => '2.0',
                'id' => 4,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'nonexistent_tool',
                    'arguments' => new \stdClass(),
                ],
            ]);

            $this->assertSame(4, $response['id']);
            $this->assertArrayHasKey('error', $response);
            $this->assertSame(-32602, $response['error']['code']);
            $this->assertStringContainsString('nonexistent_tool', $response['error']['message']);
        });
    }

    public function testInvalidJsonReturnsParseError(): void
    {
        $this->withMcpProcess(function ($stdin, $stdout, $stderr): void {
            // Send malformed JSON directly (not via sendRequest)
            fwrite($stdin, "this is not json\n");
            fflush($stdin);

            $responseLine = fgets($stdout);
            self::assertIsString($responseLine);

            $response = json_decode(trim($responseLine), true);
            self::assertIsArray($response);

            $this->assertArrayHasKey('error', $response);
            $this->assertSame(-32700, $response['error']['code']);
            $this->assertSame('Parse error', $response['error']['message']);
        });
    }

    public function testUnknownMethodReturnsError(): void
    {
        $this->withMcpProcess(function ($stdin, $stdout, $stderr): void {
            $this->sendInitialize($stdin, $stdout);

            $response = $this->sendRequest($stdin, $stdout, [
                'jsonrpc' => '2.0',
                'id' => 5,
                'method' => 'bogus/method',
                'params' => new \stdClass(),
            ]);

            $this->assertSame(5, $response['id']);
            $this->assertArrayHasKey('error', $response);
            $this->assertSame(-32601, $response['error']['code']);
            $this->assertStringContainsString('bogus/method', $response['error']['message']);
        });
    }

    public function testUiToolsNotAvailableWithoutControl(): void
    {
        $this->withMcpProcess(function ($stdin, $stdout, $stderr): void {
            $this->sendInitialize($stdin, $stdout);

            $response = $this->sendRequest($stdin, $stdout, [
                'jsonrpc' => '2.0',
                'id' => 6,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'rmem_navigate',
                    'arguments' => ['node_id' => 1],
                ],
            ]);

            $this->assertSame(6, $response['id']);
            $this->assertArrayHasKey('result', $response);
            // The tool exists in the mapping but should return an error result
            $content = $response['result']['content'];
            $this->assertTrue($response['result']['isError']);
            $this->assertStringContainsString('--control', $content[0]['text']);
        });
    }

    public function testPingMethod(): void
    {
        $this->withMcpProcess(function ($stdin, $stdout, $stderr): void {
            $this->sendInitialize($stdin, $stdout);

            $response = $this->sendRequest($stdin, $stdout, [
                'jsonrpc' => '2.0',
                'id' => 7,
                'method' => 'ping',
                'params' => new \stdClass(),
            ]);

            $this->assertSame(7, $response['id']);
            $this->assertArrayHasKey('result', $response);
            $this->assertSame([], $response['result']);
        });
    }

    public function testMultipleRequestsInSequence(): void
    {
        $this->withMcpProcess(function ($stdin, $stdout, $stderr): void {
            // Initialize
            $this->sendInitialize($stdin, $stdout);

            // Send notifications/initialized (no response expected)
            fwrite($stdin, json_encode([
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ]) . "\n");
            fflush($stdin);

            // tools/list
            $r1 = $this->sendRequest($stdin, $stdout, [
                'jsonrpc' => '2.0',
                'id' => 10,
                'method' => 'tools/list',
                'params' => new \stdClass(),
            ]);
            $this->assertSame(10, $r1['id']);
            $this->assertArrayHasKey('result', $r1);

            // tools/call rmem_hello
            $r2 = $this->sendRequest($stdin, $stdout, [
                'jsonrpc' => '2.0',
                'id' => 11,
                'method' => 'tools/call',
                'params' => ['name' => 'rmem_hello', 'arguments' => new \stdClass()],
            ]);
            $this->assertSame(11, $r2['id']);
            $this->assertArrayHasKey('result', $r2);

            // ping
            $r3 = $this->sendRequest($stdin, $stdout, [
                'jsonrpc' => '2.0',
                'id' => 12,
                'method' => 'ping',
                'params' => new \stdClass(),
            ]);
            $this->assertSame(12, $r3['id']);
        });
    }
}
