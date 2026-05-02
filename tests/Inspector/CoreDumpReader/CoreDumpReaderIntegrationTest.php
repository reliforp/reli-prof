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

namespace Reli\Inspector\CoreDumpReader;

use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Reli\BaseTestCase;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettings;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\System\Architecture;
use Reli\TargetPhpVmProvider;

#[Group('target-version')]
#[Group('coredump')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class CoreDumpReaderIntegrationTest extends BaseTestCase
{
    /** @var resource|null */
    private $child = null;

    private ?string $core_file = null;
    private ?string $output_file = null;

    protected function setUp(): void
    {
        exec('which gcore 2>/dev/null', $output, $exit_code);
        if ($exit_code !== 0) {
            $this->markTestSkipped('gcore (from gdb) is not available');
        }
    }

    protected function tearDown(): void
    {
        if (!is_null($this->child)) {
            $child_status = proc_get_status($this->child);
            if (is_array($child_status)) {
                if ($child_status['running']) {
                    posix_kill($child_status['pid'], SIGKILL);
                }
            }
        }
        if ($this->core_file !== null && file_exists($this->core_file)) {
            unlink($this->core_file);
        }
        if ($this->output_file !== null && file_exists($this->output_file)) {
            unlink($this->output_file);
        }
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testReadFromCoreDump(
        string $php_version,
        string $docker_image_name,
    ): void {
        ini_set('memory_limit', '2G');

        // ARM64 ZTS coredump: `CoreDumpThreadPointerRetriever` only knows
        // how to read x86_64 `fs_base` from `NT_PRSTATUS`; AArch64
        // `TPIDR_EL0` recovery is not implemented yet, so live attach
        // works on AArch64 ZTS but post-mortem does not. Skip here until
        // the AArch64 retriever lands; x86_64 ZTS is exercised on the
        // primary CI runner.
        if (
            str_contains($docker_image_name, '-zts')
            && Architecture::detect() === Architecture::AARCH64
        ) {
            $this->markTestSkipped(
                'AArch64 ZTS coredump: TPIDR_EL0 retrieval from NT_PRSTATUS not yet implemented'
            );
        }

        $target_script = <<<'CODE'
            <?php
            $data = array_fill(0, 1000, str_repeat('x', 100));
            $obj = new stdClass();
            $obj->items = range(1, 50);
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );

        $s = fgets($pipes[1]);
        $this->assertSame("ready\n", $s);

        // Take coredump via gcore (non-destructive)
        $this->core_file = $this->takeCoreDump($pid);
        $this->assertFileExists($this->core_file);

        // Set up CoreDumpReaderFactory
        $integer_reader = new LittleEndianReader();
        $elf64_parser = new Elf64Parser($integer_reader);
        $container_builder = new ContainerBuilder();
        $factory = new CoreDumpReaderFactory($container_builder, $elf64_parser);

        // Use /proc/<pid>/root/ as dependency root (container filesystem via host PID mode)
        $path_mapping = ['/' => "/proc/{$pid}/root"];

        $core_dump_reader = $factory->createFromPath($this->core_file, $path_mapping);

        // Run full memory analysis with JSON output
        $this->output_file = tempnam('/tmp/reli-test', 'coredump-test-output-');
        $target_php_settings = new TargetPhpSettings(
            php_version: $php_version,
        );
        $memory_profiler_settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'json',
            output_path: $this->output_file,
        );

        $core_dump_reader->read(
            $pid,
            $target_php_settings,
            $memory_profiler_settings,
        );

        // Verify JSON output
        $this->assertFileExists($this->output_file);
        $json_content = file_get_contents($this->output_file);
        $this->assertNotFalse($json_content);
        $this->assertNotEmpty($json_content);

        $result = json_decode($json_content, true);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);

        $summary = $result['summary'][0];
        $this->assertSame($php_version, $summary['php_version']);
        $this->assertGreaterThan(0, $summary['memory_get_usage']);
        $this->assertGreaterThan(0, $summary['memory_get_real_usage']);
        $this->assertArrayHasKey('memory_get_peak_usage', $summary);
        $this->assertGreaterThan(0, $summary['memory_get_peak_usage']);
        $this->assertArrayHasKey('memory_limit', $summary);
        $this->assertGreaterThan(0, $summary['memory_limit']);

        // ZendMM chunk counters and fragmentation aggregates surfaced from
        // the heap_slot + chunk walk. The live process has at least one
        // in-use chunk (main_chunk), and the heuristic fields are always
        // present even when they're zero.
        $this->assertArrayHasKey('chunks_count', $summary);
        $this->assertGreaterThanOrEqual(1, $summary['chunks_count']);
        $this->assertArrayHasKey('peak_chunks_count', $summary);
        $this->assertGreaterThanOrEqual(
            (int)$summary['chunks_count'],
            (int)$summary['peak_chunks_count'],
        );
        $this->assertArrayHasKey('cached_chunks_count', $summary);
        $this->assertArrayHasKey('last_chunks_delete_boundary', $summary);
        $this->assertArrayHasKey('last_chunks_delete_count', $summary);
        $this->assertArrayHasKey('chunks_total_free_bytes', $summary);
        $this->assertGreaterThanOrEqual(0, $summary['chunks_total_free_bytes']);
        $this->assertArrayHasKey('chunks_mostly_empty_count', $summary);
        $this->assertGreaterThanOrEqual(0, $summary['chunks_mostly_empty_count']);
    }

    /**
     * `inspector:coredump -f rmem` previously failed at the
     * `BinaryMemoryOutput::output()` boundary (BinaryMemoryOutput
     * only supports the streaming sink path, but the coredump
     * reader was always going through the PDO temp-DB path and
     * then asking the factory for a non-streaming output).
     * CoreDumpReader now branches on isRmemFormat() and uses
     * createBinaryStreamingSink() / finalizeStreaming(),
     * mirroring the pattern in MemoryDumpReader.
     */
    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testReadFromCoreDumpToRmem(
        string $php_version,
        string $docker_image_name,
    ): void {
        ini_set('memory_limit', '2G');

        // ARM64 ZTS coredump: `CoreDumpThreadPointerRetriever` only knows
        // how to read x86_64 `fs_base` from `NT_PRSTATUS`; AArch64
        // `TPIDR_EL0` recovery is not implemented yet, so live attach
        // works on AArch64 ZTS but post-mortem does not. Skip here until
        // the AArch64 retriever lands; x86_64 ZTS is exercised on the
        // primary CI runner.
        if (
            str_contains($docker_image_name, '-zts')
            && Architecture::detect() === Architecture::AARCH64
        ) {
            $this->markTestSkipped(
                'AArch64 ZTS coredump: TPIDR_EL0 retrieval from NT_PRSTATUS not yet implemented'
            );
        }

        $target_script = <<<'CODE'
            <?php
            $data = array_fill(0, 1000, str_repeat('x', 100));
            $obj = new stdClass();
            $obj->items = range(1, 50);
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );

        $s = fgets($pipes[1]);
        $this->assertSame("ready\n", $s);

        $this->core_file = $this->takeCoreDump($pid);
        $this->assertFileExists($this->core_file);

        $integer_reader = new LittleEndianReader();
        $elf64_parser = new Elf64Parser($integer_reader);
        $container_builder = new ContainerBuilder();
        $factory = new CoreDumpReaderFactory($container_builder, $elf64_parser);

        $path_mapping = ['/' => "/proc/{$pid}/root"];
        $core_dump_reader = $factory->createFromPath($this->core_file, $path_mapping);

        // Output format: rmem (.rmem binary) — exercises the
        // createBinaryStreamingSink / finalizeStreaming path.
        $this->output_file = tempnam('/tmp/reli-test', 'coredump-test-rmem-');
        $target_php_settings = new TargetPhpSettings(
            php_version: $php_version,
        );
        $memory_profiler_settings = new MemoryProfilerSettings(
            stop_process: false,
            pretty_print: false,
            output_format: 'rmem',
            output_path: $this->output_file,
        );

        $core_dump_reader->read(
            $pid,
            $target_php_settings,
            $memory_profiler_settings,
        );

        // Verify the output is a real .rmem file: non-empty, starts
        // with the RMEM magic. Full structural validation is covered
        // by the BinaryFormat reader tests; here we just guard against
        // a zero-byte file or a regression to "BinaryMemoryOutput
        // does not support the non-streaming output() path".
        $this->assertFileExists($this->output_file);
        $size = filesize($this->output_file);
        $this->assertNotFalse($size);
        $this->assertGreaterThan(0, $size);

        $magic = file_get_contents($this->output_file, false, null, 0, 4);
        $this->assertSame('RMEM', $magic);
    }

    private function takeCoreDump(int $pid): string
    {
        // Include all memory pages in the coredump (file-backed pages are needed
        // for symbol resolution from read-only segments like .dynamic, .got, etc.)
        @file_put_contents("/proc/{$pid}/coredump_filter", '0x7f');

        $core_prefix = '/tmp/reli-test/coredump-test-core';
        $command = sprintf('gcore -o %s %d 2>&1', escapeshellarg($core_prefix), $pid);
        exec($command, $output, $exit_code);
        if ($exit_code !== 0) {
            $this->fail('gcore failed (exit code ' . $exit_code . '): ' . implode("\n", $output));
        }
        $core_path = $core_prefix . '.' . $pid;
        if (!file_exists($core_path)) {
            $this->fail('gcore did not produce expected file: ' . $core_path . "\nOutput: " . implode("\n", $output));
        }
        return $core_path;
    }
}
