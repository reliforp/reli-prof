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
            public $name;
            public $email;
            public $data;
            public function __construct($name) {
                $this->name = $name;
                $this->email = $name . '@example.com';
                $this->data = range(1, 10);
            }
        }
        class TestItem {
            public $label;
            public function __construct($label) {
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
        $dump_args = [
            '--pid' => (string)$pid,
            '--output' => $dump_path,
            '--include-binary' => true,
        ];
        if (str_contains($docker_image_name, 'alpine')) {
            $dump_args['--include-heap'] = true;
        }
        $dump_input = new ArrayInput($dump_args);
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
        $dump_args = [
            '--pid' => (string)$pid,
            '--output' => $dump_path,
            '--include-binary' => true,
        ];
        if (str_contains($docker_image_name, 'alpine')) {
            $dump_args['--include-heap'] = true;
        }
        $dump_input = new ArrayInput($dump_args);
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

    #[DataProvider('provideFromV72')]
    public function testBinaryAnalyzeReportMatchesSqliteReportForKeyFindings(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $dump_path = $this->createTmpFile();
        $db_path = $this->createTmpFile('.db');
        $rmem_path = $this->createTmpFile('.rmem');
        $sqlite_report_path = $this->createTmpFile('.sqlite-report.json');
        $binary_report_path = $this->createTmpFile('.binary-report.json');
        $dump_container = $this->createContainer();
        [, $pid, ] = $this->startTargetProcess($docker_image_name);

        /** @var MemoryDumpCommand $dump_command */
        $dump_command = $dump_container->make(MemoryDumpCommand::class);
        $dump_args = [
            '--pid' => (string)$pid,
            '--output' => $dump_path,
            '--include-binary' => true,
        ];
        if (str_contains($docker_image_name, 'alpine')) {
            $dump_args['--include-heap'] = true;
        }
        $dump_input = new ArrayInput($dump_args);
        $dump_input->setInteractive(false);
        $this->assertSame(0, $dump_command->run($dump_input, new BufferedOutput()));

        /** @var MemoryAnalyzeCommand $sqlite_analyze_command */
        $sqlite_analyze_command = $this->createContainer()->make(MemoryAnalyzeCommand::class);

        $sqlite_analyze_input = new ArrayInput([
            'dump-file' => $dump_path,
            '--output-format' => 'sqlite3',
            '--output' => $db_path,
        ]);
        $sqlite_analyze_input->setInteractive(false);
        ob_start();
        try {
            $sqlite_analyze_result = $sqlite_analyze_command->run($sqlite_analyze_input, new BufferedOutput());
        } finally {
            ob_end_clean();
        }
        $this->assertSame(0, $sqlite_analyze_result);

        /** @var MemoryAnalyzeCommand $binary_analyze_command */
        $binary_analyze_command = $this->createContainer()->make(MemoryAnalyzeCommand::class);
        $binary_analyze_input = new ArrayInput([
            'dump-file' => $dump_path,
            '--output-format' => 'binary',
            '--output' => $rmem_path,
        ]);
        $binary_analyze_input->setInteractive(false);
        ob_start();
        try {
            $binary_analyze_result = $binary_analyze_command->run($binary_analyze_input, new BufferedOutput());
        } finally {
            ob_end_clean();
        }
        $this->assertSame(0, $binary_analyze_result);

        /** @var MemoryReportCommand $report_command */
        $report_command = $this->createContainer()->make(MemoryReportCommand::class);

        $sqlite_report_input = new ArrayInput([
            'db-file' => $db_path,
            '--output-format' => 'report-json',
            '--output' => $sqlite_report_path,
        ]);
        $sqlite_report_input->setInteractive(false);
        $this->assertSame(0, $report_command->run($sqlite_report_input, new BufferedOutput()));

        $binary_report_input = new ArrayInput([
            'db-file' => $rmem_path,
            '--output-format' => 'report-json',
            '--output' => $binary_report_path,
        ]);
        $binary_report_input->setInteractive(false);
        $this->assertSame(0, $report_command->run($binary_report_input, new BufferedOutput()));

        $sqlite_json = file_get_contents($sqlite_report_path);
        $binary_json = file_get_contents($binary_report_path);
        $this->assertNotFalse($sqlite_json);
        $this->assertNotFalse($binary_json);

        $sqlite_report = json_decode($sqlite_json, true);
        $binary_report = json_decode($binary_json, true);
        $this->assertIsArray($sqlite_report);
        $this->assertIsArray($binary_report);
        $this->assertSame(
            $sqlite_report['meta']['heap_memory_analyzed_percentage'] ?? null,
            $binary_report['meta']['heap_memory_analyzed_percentage'] ?? null,
        );

        $sqlite_findings = $sqlite_report['findings'] ?? [];
        $binary_findings = $binary_report['findings'] ?? [];
        $this->assertIsArray($sqlite_findings);
        $this->assertIsArray($binary_findings);

        $this->assertSame(
            $this->indexTypeRanking($sqlite_findings),
            $this->indexTypeRanking($binary_findings),
        );
        $this->assertSame(
            $this->indexClassRanking($sqlite_findings),
            $this->indexClassRanking($binary_findings),
        );
        $this->assertSame(
            $this->indexRootBlame($sqlite_findings),
            $this->indexRootBlame($binary_findings),
        );

        $sqlite_bottleneck = $this->firstFindingByKind($sqlite_findings, 'bottleneck_path');
        $binary_bottleneck = $this->firstFindingByKind($binary_findings, 'bottleneck_path');
        $this->assertNotNull($sqlite_bottleneck);
        $this->assertNotNull($binary_bottleneck);
        $this->assertSame($sqlite_bottleneck['impact_bytes'], $binary_bottleneck['impact_bytes']);
        $this->assertSame(
            $sqlite_bottleneck['facts']['summary_path'] ?? null,
            $binary_bottleneck['facts']['summary_path'] ?? null,
        );
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, int>
     */
    private function indexTypeRanking(array $findings): array
    {
        $indexed = [];
        foreach ($findings as $finding) {
            if (($finding['kind'] ?? null) !== 'type_ranking') {
                continue;
            }
            $facts = $finding['facts'] ?? null;
            if (!is_array($facts) || !isset($facts['type'], $facts['memory_usage'])) {
                continue;
            }
            $indexed[(string)$facts['type']] = (int)$facts['memory_usage'];
        }
        ksort($indexed);
        return $indexed;
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, int>
     */
    private function indexClassRanking(array $findings): array
    {
        $indexed = [];
        foreach ($findings as $finding) {
            if (($finding['kind'] ?? null) !== 'class_ranking') {
                continue;
            }
            $facts = $finding['facts'] ?? null;
            if (!is_array($facts)) {
                continue;
            }
            $class_name = $facts['class_name'] ?? $facts['class'] ?? null;
            $memory_usage = $facts['memory_usage'] ?? $facts['memory_bytes'] ?? null;
            if (!is_string($class_name) || !is_int($memory_usage)) {
                continue;
            }
            $indexed[$class_name] = $memory_usage;
        }
        ksort($indexed);
        return $indexed;
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, int>
     */
    private function indexRootBlame(array $findings): array
    {
        $indexed = [];
        foreach ($findings as $finding) {
            if (($finding['kind'] ?? null) !== 'root_blame') {
                continue;
            }
            $facts = $finding['facts'] ?? null;
            if (!is_array($facts) || !isset($facts['root_name'], $facts['total_bytes'])) {
                continue;
            }
            $indexed[(string)$facts['root_name']] = (int)$facts['total_bytes'];
        }
        ksort($indexed);
        return $indexed;
    }

    /**
     * @param list<array<string, mixed>> $findings
     * @return array<string, mixed>|null
     */
    private function firstFindingByKind(array $findings, string $kind): ?array
    {
        foreach ($findings as $finding) {
            if (($finding['kind'] ?? null) === $kind) {
                return $finding;
            }
        }
        return null;
    }
}
