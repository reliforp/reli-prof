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

use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Reli\BaseTestCase;
use Reli\Inspector\Sidecar\Protocol\SidecarRequest;
use Reli\Inspector\Sidecar\SidecarDumpHandler;
use Reli\Inspector\Sidecar\SidecarServer;
use Reli\Inspector\Watch\DiskUsageTracker;
use Reli\TargetPhpVmProvider;
use Symfony\Component\Console\Output\BufferedOutput;

#[Group('target-version')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class SidecarCommandIntegrationTest extends BaseTestCase
{
    /** @var resource|null */
    private $child = null;

    /** @var list<string> */
    private array $tmp_files = [];

    private string $memory_limit_backup;

    protected function setUp(): void
    {
        $this->child = null;
        $this->memory_limit_backup = ini_get('memory_limit');
        ini_set('memory_limit', '1G');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memory_limit_backup);
        if (!is_null($this->child)) {
            $child_status = proc_get_status($this->child);
            if (is_array($child_status) && $child_status['running']) {
                posix_kill($child_status['pid'], SIGKILL);
            }
            $this->child = null;
        }
        foreach ($this->tmp_files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
            // Also clean up .meta.json
            if (file_exists($file . '.meta.json')) {
                @unlink($file . '.meta.json');
            }
        }
        parent::tearDown();
    }

    private function createContainer(): \DI\Container
    {
        return (new ContainerBuilder())
            ->addDefinitions(__DIR__ . '/../../../config/di.php')
            ->build();
    }

    /**
     * @return array{resource, int, array<int, resource>}
     */
    private function startTargetProcess(string $docker_image_name): array
    {
        $target_script = <<<'PHP'
        <?php
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $data[] = str_repeat('x', 1024);
        }
        fputs(STDOUT, "ready\n");
        fgets(STDIN);
        PHP;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );
        [$ready, $seen] = TargetPhpVmProvider::waitForMarkerLine($pipes[1], 'ready');
        $this->assertTrue($ready, "child did not print 'ready'. Got: " . var_export($seen, true));

        return [$this->child, $pid, $pipes];
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testDumpHandlerCreatesDumpAndMetadata(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $output_dir = sys_get_temp_dir() . '/reli_sidecar_test_' . uniqid();
        mkdir($output_dir, 0777, true);
        $this->tmp_files[] = $output_dir;

        $container = $this->createContainer();
        [, $pid, ] = $this->startTargetProcess($docker_image_name);

        /** @var SidecarDumpHandler $handler */
        $handler = new SidecarDumpHandler(
            php_globals_finder: $container->get(\Reli\Lib\PhpProcessReader\PhpGlobalsFinder::class),
            php_version_detector: $container->get(\Reli\Lib\PhpProcessReader\PhpVersionDetector::class),
            memory_dumper: $container->get(\Reli\Inspector\MemoryDump\MemoryDumper::class),
            call_trace_reader: $container->get(\Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class),
            heap_stats_reader: $container->get(\Reli\Inspector\Watch\HeapStatsReader::class),
            rss_reader: $container->get(\Reli\Inspector\Watch\RssReader::class),
            process_stopper: $container->get(\Reli\Lib\Process\ProcessStopper\ProcessStopper::class),
            disk_tracker: new DiskUsageTracker(0),
            output_dir: $output_dir,
            include_binary: false,
            session_tags: ['product' => 'test-app', 'version' => '1.0'],
        );

        $request = new SidecarRequest(
            command: 'dump',
            pid: $pid,
            label: 'integration-test',
            metadata: ['step' => 'after-setup'],
        );

        $response = $handler->handle($request);

        $this->assertSame('ok', $response->status);
        $this->assertNotNull($response->path);
        $this->assertGreaterThan(0, $response->bytes);

        // Verify dump file
        $this->assertFileExists($response->path);
        $magic = file_get_contents($response->path, false, null, 0, 8);
        $this->assertSame("RDUMP\0\0\0", $magic);
        $this->tmp_files[] = $response->path;

        // Verify .meta.json
        $meta_path = $response->path . '.meta.json';
        $this->assertFileExists($meta_path);
        $meta = json_decode(file_get_contents($meta_path), true);
        $this->assertSame($pid, $meta['pid']);
        $this->assertSame('sidecar_request', $meta['trigger']);
        $this->assertSame('integration-test', $meta['label']);
        // Session tags merged with per-request metadata
        $this->assertSame('test-app', $meta['metadata']['product']);
        $this->assertSame('1.0', $meta['metadata']['version']);
        $this->assertSame('after-setup', $meta['metadata']['step']);

        // Verify memory_stats
        $this->assertArrayHasKey('memory_stats', $meta);
        $this->assertGreaterThan(0, $meta['memory_stats']['memory_usage']);
        $this->assertGreaterThan(0, $meta['memory_stats']['memory_real_usage']);

        // Verify call trace is captured
        $this->assertArrayHasKey('call_trace', $meta);

        // Verify response also has memory_stats
        $this->assertNotNull($response->memory_stats);
        $this->assertGreaterThan(0, $response->memory_stats['memory_usage']);

        // Filename contains label slug
        $this->assertStringContainsString('integration-test', basename($response->path));

        // Cleanup directory
        array_map('unlink', glob($output_dir . '/*'));
        rmdir($output_dir);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testDumpHandlerReturnsErrorForNonExistentPid(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $container = $this->createContainer();

        // Start a process just so DI setup works (some deps need FFI init)
        $this->startTargetProcess($docker_image_name);

        $handler = new SidecarDumpHandler(
            php_globals_finder: $container->get(\Reli\Lib\PhpProcessReader\PhpGlobalsFinder::class),
            php_version_detector: $container->get(\Reli\Lib\PhpProcessReader\PhpVersionDetector::class),
            memory_dumper: $container->get(\Reli\Inspector\MemoryDump\MemoryDumper::class),
            call_trace_reader: $container->get(\Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class),
            heap_stats_reader: $container->get(\Reli\Inspector\Watch\HeapStatsReader::class),
            rss_reader: $container->get(\Reli\Inspector\Watch\RssReader::class),
            process_stopper: $container->get(\Reli\Lib\Process\ProcessStopper\ProcessStopper::class),
            disk_tracker: new DiskUsageTracker(0),
            output_dir: sys_get_temp_dir(),
            include_binary: false,
        );

        $request = new SidecarRequest(
            command: 'dump',
            pid: 999999999,
        );

        $response = $handler->handle($request);

        $this->assertSame('error', $response->status);
        $this->assertStringContainsString('not found', $response->message);
    }

    public function testSidecarServerRejectsUnknownCommand(): void
    {
        $socket_path = tempnam(sys_get_temp_dir(), 'reli_sock_');
        unlink($socket_path);
        $this->tmp_files[] = $socket_path;

        $container = $this->createContainer();
        $handler = new SidecarDumpHandler(
            php_globals_finder: $container->get(\Reli\Lib\PhpProcessReader\PhpGlobalsFinder::class),
            php_version_detector: $container->get(\Reli\Lib\PhpProcessReader\PhpVersionDetector::class),
            memory_dumper: $container->get(\Reli\Inspector\MemoryDump\MemoryDumper::class),
            call_trace_reader: $container->get(\Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class),
            heap_stats_reader: $container->get(\Reli\Inspector\Watch\HeapStatsReader::class),
            rss_reader: $container->get(\Reli\Inspector\Watch\RssReader::class),
            process_stopper: $container->get(\Reli\Lib\Process\ProcessStopper\ProcessStopper::class),
            disk_tracker: new DiskUsageTracker(0),
            output_dir: sys_get_temp_dir(),
            include_binary: false,
        );
        $server = new SidecarServer($handler);

        // Start server in child process
        $child = pcntl_fork();
        if ($child === 0) {
            $output = new BufferedOutput();
            $server->run($socket_path, $output);
            exit(0);
        }

        // Wait for socket to appear
        $timeout = 5;
        $start = time();
        while (!file_exists($socket_path) && time() - $start < $timeout) {
            usleep(50000);
        }
        $this->assertFileExists($socket_path);

        // Send unknown command
        $sock = stream_socket_client("unix://{$socket_path}", $errno, $errstr, 5);
        $this->assertNotFalse($sock);
        fwrite($sock, json_encode(['command' => 'unknown', 'pid' => 1]) . "\n");
        $response_line = fgets($sock, 8192);
        fclose($sock);

        $response = json_decode(trim($response_line), true);
        $this->assertSame('error', $response['status']);
        $this->assertStringContainsString('unknown command', $response['message']);

        // Clean up server
        posix_kill($child, SIGTERM);
        pcntl_waitpid($child, $status);
    }

    public function testSidecarServerRejectsInvalidJson(): void
    {
        $socket_path = tempnam(sys_get_temp_dir(), 'reli_sock_');
        unlink($socket_path);
        $this->tmp_files[] = $socket_path;

        $container = $this->createContainer();
        $handler = new SidecarDumpHandler(
            php_globals_finder: $container->get(\Reli\Lib\PhpProcessReader\PhpGlobalsFinder::class),
            php_version_detector: $container->get(\Reli\Lib\PhpProcessReader\PhpVersionDetector::class),
            memory_dumper: $container->get(\Reli\Inspector\MemoryDump\MemoryDumper::class),
            call_trace_reader: $container->get(\Reli\Lib\PhpProcessReader\CallTraceReader\CallTraceReader::class),
            heap_stats_reader: $container->get(\Reli\Inspector\Watch\HeapStatsReader::class),
            rss_reader: $container->get(\Reli\Inspector\Watch\RssReader::class),
            process_stopper: $container->get(\Reli\Lib\Process\ProcessStopper\ProcessStopper::class),
            disk_tracker: new DiskUsageTracker(0),
            output_dir: sys_get_temp_dir(),
            include_binary: false,
        );
        $server = new SidecarServer($handler);

        $child = pcntl_fork();
        if ($child === 0) {
            $server->run($socket_path, new BufferedOutput());
            exit(0);
        }

        $timeout = 5;
        $start = time();
        while (!file_exists($socket_path) && time() - $start < $timeout) {
            usleep(50000);
        }

        $sock = stream_socket_client("unix://{$socket_path}", $errno, $errstr, 5);
        $this->assertNotFalse($sock);
        fwrite($sock, "not valid json\n");
        $response_line = fgets($sock, 8192);
        fclose($sock);

        $response = json_decode(trim($response_line), true);
        $this->assertSame('error', $response['status']);
        $this->assertStringContainsString('invalid request', $response['message']);

        posix_kill($child, SIGTERM);
        pcntl_waitpid($child, $status);
    }
}
