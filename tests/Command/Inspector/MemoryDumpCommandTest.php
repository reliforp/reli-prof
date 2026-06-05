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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Reli\BaseTestCase;
use Reli\Inspector\MemoryDump\MemoryDumpReaderFactory;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\TargetPhpVmProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[Group('target-version')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class MemoryDumpCommandTest extends BaseTestCase
{
    /** @var resource|null */
    private $child = null;

    private string $memory_limit_backup;

    /** @var list<string> */
    private array $tmp_files = [];

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
                unlink($file);
            }
        }
    }

    private function createTmpFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_dump_test_');
        assert($path !== false);
        $this->tmp_files[] = $path;
        return $path;
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

    public static function provideFromV72(): \Generator
    {
        yield from TargetPhpVmProvider::from(ZendTypeReader::V72);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testDumpCreatesValidFile(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $output_path = $this->createTmpFile();
        $container = $this->createContainer();
        [, $pid, ] = $this->startTargetProcess($docker_image_name);

        /** @var MemoryDumpCommand $command */
        $command = $container->make(MemoryDumpCommand::class);

        $input = new ArrayInput([
            '--pid' => (string)$pid,
            '--output' => $output_path,
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        $this->assertSame(0, $result);

        $outputText = $output->fetch();
        $this->assertStringContainsString('Memory dump written to', $outputText);
        $this->assertStringContainsString($output_path, $outputText);

        // Verify file is non-empty and starts with the correct magic
        $this->assertFileExists($output_path);
        $this->assertGreaterThan(0, filesize($output_path));
        $magic = file_get_contents($output_path, false, null, 0, 8);
        $this->assertSame("RDUMP\0\0\0", $magic);

        // Verify the dump can be parsed back via MemoryDumpReaderFactory
        $reader_factory = $container->make(MemoryDumpReaderFactory::class);
        $reader = $reader_factory->createFromPath($output_path, []);
        $this->assertNotNull($reader);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testDumpWithNoStopProcess(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $output_path = $this->createTmpFile();
        $container = $this->createContainer();
        [, $pid, ] = $this->startTargetProcess($docker_image_name);

        /** @var MemoryDumpCommand $command */
        $command = $container->make(MemoryDumpCommand::class);

        $input = new ArrayInput([
            '--pid' => (string)$pid,
            '--output' => $output_path,
            '--no-stop-process' => true,
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Memory dump written to', $output->fetch());

        // Verify valid dump file
        $this->assertGreaterThan(0, filesize($output_path));
        $magic = file_get_contents($output_path, false, null, 0, 8);
        $this->assertSame("RDUMP\0\0\0", $magic);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testDumpWithIncludeBinary(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $output_path_with = $this->createTmpFile();
        $output_path_without = $this->createTmpFile();
        $container = $this->createContainer();

        // First dump without --include-binary
        [, $pid, $pipes] = $this->startTargetProcess($docker_image_name);

        /** @var MemoryDumpCommand $command */
        $command = $container->make(MemoryDumpCommand::class);

        $input = new ArrayInput([
            '--pid' => (string)$pid,
            '--output' => $output_path_without,
        ]);
        $input->setInteractive(false);
        $result = $command->run($input, new BufferedOutput());
        $this->assertSame(0, $result);

        // Terminate first process
        $child_status = proc_get_status($this->child);
        if (is_array($child_status) && $child_status['running']) {
            posix_kill($child_status['pid'], SIGKILL);
        }
        $this->child = null;

        // Second dump with --include-binary
        [, $pid2, ] = $this->startTargetProcess($docker_image_name);

        /** @var MemoryDumpCommand $command2 */
        $command2 = $container->make(MemoryDumpCommand::class);

        $input2 = new ArrayInput([
            '--pid' => (string)$pid2,
            '--output' => $output_path_with,
            '--include-binary' => true,
        ]);
        $input2->setInteractive(false);
        $result2 = $command2->run($input2, new BufferedOutput());
        $this->assertSame(0, $result2);

        // The dump with binary segments should be larger
        $size_without = filesize($output_path_without);
        $size_with = filesize($output_path_with);
        $this->assertGreaterThan($size_without, $size_with);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testDumpContainsExpectedRegions(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $output_path = $this->createTmpFile();
        $container = $this->createContainer();
        [, $pid, ] = $this->startTargetProcess($docker_image_name);

        /** @var MemoryDumpCommand $command */
        $command = $container->make(MemoryDumpCommand::class);

        $input = new ArrayInput([
            '--pid' => (string)$pid,
            '--output' => $output_path,
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);
        $this->assertSame(0, $result);

        // Parse the output to verify region count
        $outputText = $output->fetch();
        $this->assertMatchesRegularExpression(
            '/\d+ regions/',
            $outputText,
        );

        // Extract region count from output and verify it's reasonable
        preg_match('/(\d+) regions/', $outputText, $matches);
        $region_count = (int)$matches[1];
        // At minimum: EG + CG + at least 1 ZendMM chunk = 3 regions
        $this->assertGreaterThanOrEqual(3, $region_count);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testDumpParsedByReaderFactory(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $output_path = $this->createTmpFile();
        $container = $this->createContainer();
        [, $pid, ] = $this->startTargetProcess($docker_image_name);

        /** @var MemoryDumpCommand $command */
        $command = $container->make(MemoryDumpCommand::class);

        $input = new ArrayInput([
            '--pid' => (string)$pid,
            '--output' => $output_path,
        ]);
        $input->setInteractive(false);
        $result = $command->run($input, new BufferedOutput());
        $this->assertSame(0, $result);

        // Verify the factory can parse the dump and create a reader
        $reader_factory = $container->make(MemoryDumpReaderFactory::class);
        $reader = $reader_factory->createFromPath($output_path, []);
        $this->assertInstanceOf(
            \Reli\Inspector\MemoryDump\MemoryDumpReader::class,
            $reader,
        );
    }

    // PHP 7.0/7.1 の内部構造だと analyzer が追うポインタ先が
    // --include-binary のカバー範囲外になるため v72 以降に限定
    #[DataProvider('provideFromV72')]
    public function testDumpAndAnalyzeRoundtrip(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $output_path = $this->createTmpFile();
        $container = $this->createContainer();
        [, $pid, ] = $this->startTargetProcess($docker_image_name);

        // Save maps before dump (for debug if analyze fails)
        $saved_maps = @file_get_contents("/proc/{$pid}/maps") ?: '';

        // Dump with --include-binary so analyze can resolve all addresses
        /** @var MemoryDumpCommand $dump_command */
        $dump_command = $container->make(MemoryDumpCommand::class);

        $input = new ArrayInput([
            '--pid' => (string)$pid,
            '--output' => $output_path,
            '--include-binary' => true,
        ]);
        $input->setInteractive(false);
        $dump_output = new BufferedOutput();
        $result = $dump_command->run($input, $dump_output);
        $this->assertSame(0, $result);

        // Analyze (the command writes JSON to real stdout, so capture it)
        /** @var MemoryAnalyzeCommand $analyze_command */
        $analyze_command = $container->make(MemoryAnalyzeCommand::class);

        $analyze_input = new ArrayInput([
            'dump-file' => $output_path,
        ]);
        $analyze_input->setInteractive(false);
        $analyze_output = new BufferedOutput();

        ob_start();
        try {
            $analyze_result = $analyze_command->run(
                $analyze_input,
                $analyze_output,
            );
        } catch (\Throwable $e) {
            ob_end_clean();
            // Debug: dump regions for Alpine investigation
            $debug = "Dump output: " . $dump_output->fetch() . "\n";
            $debug .= "Analyze error: " . $e->getMessage() . "\n";
            // Show maps around the failing address range
            if ($saved_maps !== '') {
                $debug .= "Target /proc/maps (rw-p only):\n";
                foreach (explode("\n", $saved_maps) as $line) {
                    if (str_contains($line, 'rw-p')) {
                        $debug .= "  {$line}\n";
                    }
                }
            }
            // Parse the dump file to show region addresses
            try {
                $dump_fp = fopen($output_path, 'rb');
                if ($dump_fp !== false) {
                    // Skip magic (8) + version (4)
                    fseek($dump_fp, 8 + 4);
                    // Skip php_version string
                    $len = unpack('V', fread($dump_fp, 4))[1];
                    fseek($dump_fp, $len, SEEK_CUR);
                    // Skip pid (8) + eg_address (8) + cg_address (8)
                    $addrs = unpack('Ppid/Peg/Pcg', fread($dump_fp, 24));
                    $debug .= sprintf("EG=0x%x CG=0x%x\n", $addrs['eg'], $addrs['cg']);
                    // memory_map_count (4) + region_count (4)
                    $counts = unpack('Vmmap_count/Vregion_count', fread($dump_fp, 8));
                    // Skip memory map entries
                    for ($i = 0; $i < $counts['mmap_count']; $i++) {
                        // 3 strings (begin, end, file_offset) + 4 bytes attrs + 1 string (device_id) + 8 bytes (inode) + 1 string (name)
                        for ($s = 0; $s < 3; $s++) {
                            $sl = unpack('V', fread($dump_fp, 4))[1];
                            fseek($dump_fp, $sl, SEEK_CUR);
                        }
                        fseek($dump_fp, 4, SEEK_CUR); // attrs
                        $sl = unpack('V', fread($dump_fp, 4))[1];
                        fseek($dump_fp, $sl, SEEK_CUR); // device_id
                        fseek($dump_fp, 8, SEEK_CUR); // inode
                        $sl = unpack('V', fread($dump_fp, 4))[1];
                        fseek($dump_fp, $sl, SEEK_CUR); // name
                    }
                    // Read memory map entries from the dump
                    // Read regions
                    $debug .= "Dump regions ({$counts['region_count']}):\n";
                    for ($i = 0; $i < $counts['region_count']; $i++) {
                        $rh = fread($dump_fp, 16);
                        if ($rh === false || strlen($rh) < 16) {
                            break;
                        }
                        $r = unpack('Paddress/Psize', $rh);
                        $debug .= sprintf(
                            "  0x%x - 0x%x (size=%d)\n",
                            $r['address'],
                            $r['address'] + $r['size'],
                            $r['size'],
                        );
                        fseek($dump_fp, (int)$r['size'], SEEK_CUR);
                    }
                    fclose($dump_fp);
                }
            } catch (\Throwable $re) {
                $debug .= "Could not parse dump: " . $re->getMessage() . "\n";
            }
            $this->fail($debug . "\n" . $e->getTraceAsString());
        } finally {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
        }
        $this->assertSame(0, $analyze_result);
    }

    // dependency-root で /proc/<pid>/root を指定して
    // include-binary なしのダンプを解析できることを確認
    #[DataProvider('provideFromV72')]
    public function testDumpAndAnalyzeWithDependencyRoot(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $output_path = $this->createTmpFile();
        $container = $this->createContainer();
        [, $pid, ] = $this->startTargetProcess($docker_image_name);

        // Dump WITHOUT --include-binary
        /** @var MemoryDumpCommand $dump_command */
        $dump_command = $container->make(MemoryDumpCommand::class);

        $input = new ArrayInput([
            '--pid' => (string)$pid,
            '--output' => $output_path,
        ]);
        $input->setInteractive(false);
        $result = $dump_command->run($input, new BufferedOutput());
        $this->assertSame(0, $result);

        // Analyze with --dependency-root pointing to the container's root
        // via /proc/<pid>/root so that read-only binary segments can be
        // resolved without --include-binary in the dump
        /** @var MemoryAnalyzeCommand $analyze_command */
        $analyze_command = $container->make(MemoryAnalyzeCommand::class);

        $dependency_root = "/proc/{$pid}/root";
        $analyze_input = new ArrayInput([
            'dump-file' => $output_path,
            '--dependency-root' => $dependency_root,
        ]);
        $analyze_input->setInteractive(false);
        $analyze_output = new BufferedOutput();

        ob_start();
        try {
            $analyze_result = $analyze_command->run(
                $analyze_input,
                $analyze_output,
            );
        } finally {
            ob_end_clean();
        }
        $this->assertSame(0, $analyze_result);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testDumpWithNoCache(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $output_path = $this->createTmpFile();
        $container = $this->createContainer();
        [, $pid, ] = $this->startTargetProcess($docker_image_name);

        /** @var MemoryDumpCommand $command */
        $command = $container->make(MemoryDumpCommand::class);

        $input = new ArrayInput([
            '--pid' => (string)$pid,
            '--output' => $output_path,
            '--no-cache' => true,
        ]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        $result = $command->run($input, $output);

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Memory dump written to', $output->fetch());
        $this->assertGreaterThan(0, filesize($output_path));
    }

    public function testDumpFailsWithoutOutput(): void
    {
        $container = $this->createContainer();

        /** @var MemoryDumpCommand $command */
        $command = $container->make(MemoryDumpCommand::class);

        $input = new ArrayInput([
            '--pid' => '1',
        ]);
        $input->setInteractive(false);

        $this->expectException(\TypeError::class);
        $command->run($input, new BufferedOutput());
    }
}
