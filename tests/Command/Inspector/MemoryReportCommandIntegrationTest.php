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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Reli\BaseTestCase;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\TargetPhpVmProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[Group('target-version')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class MemoryReportCommandIntegrationTest extends BaseTestCase
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

    private function createTmpFile(string $suffix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_report_integ_');
        assert($path !== false);
        if ($suffix !== '') {
            $new_path = $path . $suffix;
            rename($path, $new_path);
            $path = $new_path;
        }
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
        // Create a target process with objects, arrays, and strings
        // to ensure all ranking categories have data
        $target_script = <<<'PHP'
        <?php
        class TestUser {
            public string $name;
            public string $email;
            public array $data;
            public function __construct(string $name) {
                $this->name = $name;
                $this->email = $name . '@example.com';
                $this->data = range(1, 10);
            }
        }
        class TestItem {
            public string $label;
            public function __construct(string $label) {
                $this->label = $label;
            }
        }
        $users = [];
        for ($i = 0; $i < 200; $i++) {
            $users[] = new TestUser("user_{$i}");
        }
        $items = [];
        for ($i = 0; $i < 100; $i++) {
            $items[] = new TestItem("item_{$i}");
        }
        $big_array = range(1, 5000);
        $big_string = str_repeat('x', 102400);
        fputs(STDOUT, "ready\n");
        fgets(STDIN);
        PHP;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );
        $ready = fgets($pipes[1]);
        $this->assertSame("ready\n", $ready);

        return [$this->child, $pid, $pipes];
    }

    public static function provideFromV72(): \Generator
    {
        yield from TargetPhpVmProvider::from(ZendTypeReader::V72);
    }

    #[DataProvider('provideFromV72')]
    public function testReportContainsRankingSections(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $dump_path = $this->createTmpFile();
        $db_path = $this->createTmpFile('.db');
        $container = $this->createContainer();
        [, $pid, ] = $this->startTargetProcess($docker_image_name);

        // Step 1: Dump memory
        /** @var MemoryDumpCommand $dump_command */
        $dump_command = $container->make(MemoryDumpCommand::class);
        $dump_input = new ArrayInput([
            '--pid' => (string)$pid,
            '--output' => $dump_path,
            '--include-binary' => true,
        ]);
        $dump_input->setInteractive(false);
        $dump_result = $dump_command->run($dump_input, new BufferedOutput());
        $this->assertSame(0, $dump_result);

        // Step 2: Analyze dump → SQLite
        /** @var MemoryAnalyzeCommand $analyze_command */
        $analyze_command = $container->make(MemoryAnalyzeCommand::class);
        $analyze_input = new ArrayInput([
            'dump-file' => $dump_path,
            '--output-format' => 'sqlite3',
            '--output' => $db_path,
        ]);
        $analyze_input->setInteractive(false);
        ob_start();
        try {
            $analyze_result = $analyze_command->run($analyze_input, new BufferedOutput());
        } finally {
            ob_end_clean();
        }
        $this->assertSame(0, $analyze_result);
        $this->assertFileExists($db_path);

        // Step 3: Generate text report
        /** @var MemoryReportCommand $report_command */
        $report_command = $container->make(MemoryReportCommand::class);
        $report_output = new BufferedOutput();
        $report_input = new ArrayInput([
            'db-file' => $db_path,
            '--output-format' => 'report',
        ]);
        $report_input->setInteractive(false);
        $report_result = $report_command->run($report_input, $report_output);
        $this->assertSame(0, $report_result);

        $text = $report_output->fetch();

        // Verify all ranking sections are present
        $this->assertStringContainsString('=== Overview ===', $text);
        $this->assertStringContainsString('=== Type Breakdown ===', $text);
        $this->assertStringContainsString('=== Top Classes by Memory ===', $text);

        // Verify type breakdown contains expected types
        $this->assertStringContainsString('ZendObject', $text);
        $this->assertStringContainsString('ZendString', $text);

        // Verify class ranking contains our test classes
        $this->assertStringContainsString('TestUser', $text);
    }

    #[DataProvider('provideFromV72')]
    public function testReportJsonContainsRankingFindings(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $dump_path = $this->createTmpFile();
        $db_path = $this->createTmpFile('.db');
        $report_path = $this->createTmpFile('.json');
        $container = $this->createContainer();
        [, $pid, ] = $this->startTargetProcess($docker_image_name);

        // Step 1: Dump
        /** @var MemoryDumpCommand $dump_command */
        $dump_command = $container->make(MemoryDumpCommand::class);
        $dump_input = new ArrayInput([
            '--pid' => (string)$pid,
            '--output' => $dump_path,
            '--include-binary' => true,
        ]);
        $dump_input->setInteractive(false);
        $dump_result = $dump_command->run($dump_input, new BufferedOutput());
        $this->assertSame(0, $dump_result);

        // Step 2: Analyze → SQLite
        /** @var MemoryAnalyzeCommand $analyze_command */
        $analyze_command = $container->make(MemoryAnalyzeCommand::class);
        $analyze_input = new ArrayInput([
            'dump-file' => $dump_path,
            '--output-format' => 'sqlite3',
            '--output' => $db_path,
        ]);
        $analyze_input->setInteractive(false);
        ob_start();
        try {
            $analyze_command->run($analyze_input, new BufferedOutput());
        } finally {
            ob_end_clean();
        }

        // Step 3: Generate JSON report
        /** @var MemoryReportCommand $report_command */
        $report_command = $container->make(MemoryReportCommand::class);
        $report_input = new ArrayInput([
            'db-file' => $db_path,
            '--output-format' => 'report-json',
            '--output' => $report_path,
        ]);
        $report_input->setInteractive(false);
        $report_result = $report_command->run($report_input, new BufferedOutput());
        $this->assertSame(0, $report_result);

        $json = file_get_contents($report_path);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('findings', $decoded);

        // Collect finding kinds
        $kinds = array_column($decoded['findings'], 'kind');

        // type_ranking and class_ranking should always be present
        $this->assertContains('type_ranking', $kinds);
        $this->assertContains('class_ranking', $kinds);

        // Verify type_ranking findings have required facts
        $type_rankings = array_filter(
            $decoded['findings'],
            fn(array $f) => $f['kind'] === 'type_ranking'
        );
        $this->assertNotEmpty($type_rankings);
        $first_type = array_values($type_rankings)[0];
        $this->assertArrayHasKey('facts', $first_type);
        $this->assertArrayHasKey('type', $first_type['facts']);
        $this->assertArrayHasKey('count', $first_type['facts']);
        $this->assertArrayHasKey('memory_usage', $first_type['facts']);
        $this->assertArrayHasKey('percentage', $first_type['facts']);

        // Verify class_ranking findings have required facts
        $class_rankings = array_filter(
            $decoded['findings'],
            fn(array $f) => $f['kind'] === 'class_ranking'
        );
        $this->assertNotEmpty($class_rankings);
        $first_class = array_values($class_rankings)[0];
        $this->assertArrayHasKey('facts', $first_class);
        $this->assertArrayHasKey('rank', $first_class['facts']);
        $this->assertArrayHasKey('class_name', $first_class['facts']);
        $this->assertArrayHasKey('count', $first_class['facts']);
        $this->assertArrayHasKey('memory_bytes', $first_class['facts']);
        $this->assertArrayHasKey('avg_size', $first_class['facts']);

        // Verify TestUser is in class rankings
        $class_names = array_map(
            fn(array $f) => $f['facts']['class_name'] ?? '',
            array_values($class_rankings)
        );
        $this->assertContains('TestUser', $class_names);
    }
}
