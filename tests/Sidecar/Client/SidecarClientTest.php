<?php

declare(strict_types=1);

namespace Reli\Sidecar\Client;

use Reli\BaseTestCase;

class SidecarClientTest extends BaseTestCase
{
    private ?string $socket_path = null;
    /** @var resource|null */
    private $server = null;

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            fclose($this->server);
            $this->server = null;
        }
        if ($this->socket_path !== null && file_exists($this->socket_path)) {
            unlink($this->socket_path);
            $this->socket_path = null;
        }
        parent::tearDown();
    }

    private function createMockServer(): string
    {
        $this->socket_path = tempnam(sys_get_temp_dir(), 'reli_test_sock_');
        unlink($this->socket_path);
        $this->server = stream_socket_server("unix://{$this->socket_path}");
        $this->assertNotFalse($this->server);
        return $this->socket_path;
    }

    public function testRequestDumpSendsCorrectPayload(): void
    {
        $path = $this->createMockServer();

        // Run client in a separate process to avoid blocking
        $client = new SidecarClient($path, timeout_seconds: 5);

        // Accept in a fiber-like fashion: spawn client request in background
        $pid_to_send = getmypid();

        // We need to handle both sides. Use non-blocking approach:
        // 1. Start client request in a forked process
        $child_pid = pcntl_fork();
        if ($child_pid === 0) {
            // Child: act as client
            $response = $client->requestDump(
                pid: 12345,
                error_file: '/app/Foo.php',
                error_line: 99,
                label: 'test-label',
                metadata: ['key' => 'val'],
            );
            // Write result to a temp file
            file_put_contents(
                $path . '.result',
                $response !== null ? $response->status : 'null',
            );
            exit(0);
        }

        // Parent: act as server
        $conn = stream_socket_accept($this->server, 5);
        $this->assertNotFalse($conn);

        $line = fgets($conn, 8192);
        $this->assertNotFalse($line);

        $request = json_decode(trim($line), true);
        $this->assertSame('dump', $request['command']);
        $this->assertSame(12345, $request['pid']);
        $this->assertSame('/app/Foo.php', $request['file']);
        $this->assertSame(99, $request['line']);
        $this->assertSame('test-label', $request['label']);
        $this->assertSame(['key' => 'val'], $request['metadata']);

        // Send response
        fwrite($conn, json_encode(['status' => 'ok', 'path' => '/tmp/d', 'bytes' => 100]) . "\n");
        fclose($conn);

        pcntl_waitpid($child_pid, $status);
        $this->assertTrue(pcntl_wifexited($status));

        $result = file_get_contents($path . '.result');
        $this->assertSame('ok', $result);
        @unlink($path . '.result');
    }

    public function testSnapshotUsesCurrentPid(): void
    {
        $path = $this->createMockServer();
        $client = new SidecarClient($path, timeout_seconds: 5);

        $child_pid = pcntl_fork();
        if ($child_pid === 0) {
            $client->snapshot('my-label', ['ver' => '1.0']);
            exit(0);
        }

        $conn = stream_socket_accept($this->server, 5);
        $this->assertNotFalse($conn);

        $line = fgets($conn, 8192);
        $request = json_decode(trim($line), true);
        $this->assertSame('dump', $request['command']);
        $this->assertSame('my-label', $request['label']);
        $this->assertSame(['ver' => '1.0'], $request['metadata']);
        // PID should be the child's PID (some positive int)
        $this->assertGreaterThan(0, $request['pid']);

        fwrite($conn, json_encode(['status' => 'ok']) . "\n");
        fclose($conn);

        pcntl_waitpid($child_pid, $status);
    }

    public function testDefaultMetadataMerge(): void
    {
        $path = $this->createMockServer();
        $client = new SidecarClient($path, timeout_seconds: 5, default_metadata: ['product' => 'app']);

        $child_pid = pcntl_fork();
        if ($child_pid === 0) {
            $client->requestDump(pid: 1, metadata: ['step' => '1']);
            exit(0);
        }

        $conn = stream_socket_accept($this->server, 5);
        $this->assertNotFalse($conn);

        $line = fgets($conn, 8192);
        $request = json_decode(trim($line), true);
        // default_metadata + per-call metadata
        $this->assertSame('app', $request['metadata']['product']);
        $this->assertSame('1', $request['metadata']['step']);

        fwrite($conn, json_encode(['status' => 'ok']) . "\n");
        fclose($conn);
        pcntl_waitpid($child_pid, $status);
    }

    public function testConnectionFailureReturnsNull(): void
    {
        $client = new SidecarClient('/nonexistent/path.sock', timeout_seconds: 1);
        $this->assertNull($client->requestDump(pid: 1));
    }

    public function testEnvSocketPath(): void
    {
        $original = getenv(SidecarClient::ENV_SOCKET_PATH);
        putenv(SidecarClient::ENV_SOCKET_PATH . '=/custom/env/path.sock');

        try {
            $client = new SidecarClient();
            // Can't easily inspect the private field, but we can test
            // that connecting to the env path fails as expected
            $this->assertNull($client->requestDump(pid: 1));
        } finally {
            if ($original === false) {
                putenv(SidecarClient::ENV_SOCKET_PATH);
            } else {
                putenv(SidecarClient::ENV_SOCKET_PATH . '=' . $original);
            }
        }
    }
}
