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
class MemoryCompareCommandIntegrationTest extends BaseTestCase
{
    /** @var list<resource> */
    private array $children = [];

    private string $memory_limit_backup;

    /** @var list<string> */
    private array $tmp_files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->children = [];
        $this->memory_limit_backup = ini_get('memory_limit');
        ini_set('memory_limit', '1G');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memory_limit_backup);
        foreach ($this->children as $child) {
            $child_status = proc_get_status($child);
            if (is_array($child_status) && $child_status['running']) {
                posix_kill($child_status['pid'], SIGKILL);
            }
        }
        $this->children = [];
        foreach ($this->tmp_files as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function createTmpFile(string $suffix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_cmp_integ_');
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
     * Dump & analyze a target process, returning the SQLite db path.
     *
     * @return string path to the SQLite database
     */
    private function captureSnapshot(
        string $docker_image_name,
        string $script,
    ): string {
        $this->snapshotTrace('begin', $docker_image_name);
        $container = $this->createContainer();
        $dump_path = $this->createTmpFile();
        $db_path = $this->createTmpFile('.db');

        $pipes = [];
        $this->snapshotTrace('runScriptViaContainer', $docker_image_name);
        [$child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $script,
            $pipes,
        );
        $this->children[] = $child;
        $ready = fgets($pipes[1]);
        $this->assertSame("ready\n", $ready);
        $this->snapshotTrace('target_ready pid=' . $pid, $docker_image_name);

        // Dump
        /** @var MemoryDumpCommand $dump_cmd */
        $dump_cmd = $container->make(MemoryDumpCommand::class);
        $dump_input = new ArrayInput([
            '--pid' => (string)$pid,
            '--output' => $dump_path,
            '--include-binary' => true,
        ]);
        $dump_input->setInteractive(false);
        $this->snapshotTrace('dump_start', $docker_image_name);
        $this->assertSame(0, $dump_cmd->run($dump_input, new BufferedOutput()));
        $this->snapshotTrace('dump_done', $docker_image_name);

        // Kill the target process — we have the dump already
        $child_status = proc_get_status($child);
        if (is_array($child_status) && $child_status['running']) {
            posix_kill($child_status['pid'], SIGKILL);
        }

        // Analyze → SQLite
        /** @var MemoryAnalyzeCommand $analyze_cmd */
        $analyze_cmd = $container->make(MemoryAnalyzeCommand::class);
        $analyze_input = new ArrayInput([
            'dump-file' => $dump_path,
            '--output-format' => 'sqlite3',
            '--output' => $db_path,
        ]);
        $analyze_input->setInteractive(false);
        $this->snapshotTrace('analyze_start', $docker_image_name);
        ob_start();
        try {
            $this->assertSame(0, $analyze_cmd->run($analyze_input, new BufferedOutput()));
        } finally {
            ob_end_clean();
        }
        $this->snapshotTrace('analyze_done', $docker_image_name);

        return $db_path;
    }

    private function snapshotTrace(string $phase, string $image): void
    {
        @file_put_contents(
            '/tmp/reli-test/reli-test-trace.log',
            sprintf(
                "[%s] pid=%d SNAPSHOT %s image=%s test=%s\n",
                date('c'),
                getmypid(),
                $phase,
                $image,
                method_exists($this, 'name') ? (string)$this->name() : static::class,
            ),
            FILE_APPEND,
        );
    }

    public static function provideFromV72(): \Generator
    {
        yield from TargetPhpVmProvider::from(ZendTypeReader::V72);
    }

    #[DataProvider('provideFromV72')]
    public function testCompareTextReport(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        // Baseline: 50 users
        $baseline_script = <<<'PHP'
        <?php
        class TestUser {
            public $name;
            public function __construct($n) { $this->name = $n; }
        }
        $users = [];
        for ($i = 0; $i < 50; $i++) { $users[] = new TestUser("u{$i}"); }
        fputs(STDOUT, "ready\n");
        fgets(STDIN);
        PHP;

        // Target: 200 users + new class
        $target_script = <<<'PHP'
        <?php
        class TestUser {
            public $name;
            public function __construct($n) { $this->name = $n; }
        }
        class TestOrder {
            public $id;
            public function __construct($id) { $this->id = $id; }
        }
        $users = [];
        for ($i = 0; $i < 200; $i++) { $users[] = new TestUser("u{$i}"); }
        $orders = [];
        for ($i = 0; $i < 100; $i++) { $orders[] = new TestOrder($i); }
        fputs(STDOUT, "ready\n");
        fgets(STDIN);
        PHP;

        $baseline_db = $this->captureSnapshot($docker_image_name, $baseline_script);
        $target_db = $this->captureSnapshot($docker_image_name, $target_script);

        // Run compare command
        $compare_cmd = new MemoryCompareCommand();
        $compare_output = new BufferedOutput();
        $compare_input = new ArrayInput([
            'baseline' => $baseline_db,
            'target' => $target_db,
            '--output-format' => 'report',
        ]);
        $compare_input->setInteractive(false);
        $this->assertSame(0, $compare_cmd->run($compare_input, $compare_output));

        $text = $compare_output->fetch();

        $this->assertStringContainsString('reli-prof Memory Comparison Report', $text);
        $this->assertStringContainsString('=== Summary Delta ===', $text);
        $this->assertStringContainsString('=== Class Memory Changes ===', $text);
        $this->assertStringContainsString('TestUser', $text);
    }

    #[DataProvider('provideFromV72')]
    public function testCompareJsonReport(
        string $php_version,
        string $docker_image_name,
    ): void {
        if ($php_version === 'skip') {
            $this->markTestSkipped('no target version');
        }

        $baseline_script = <<<'PHP'
        <?php
        class TestWidget {
            public $data;
            public function __construct() { $this->data = range(1, 10); }
        }
        $items = [];
        for ($i = 0; $i < 100; $i++) { $items[] = new TestWidget(); }
        fputs(STDOUT, "ready\n");
        fgets(STDIN);
        PHP;

        $target_script = <<<'PHP'
        <?php
        class TestWidget {
            public $data;
            public function __construct() { $this->data = range(1, 10); }
        }
        $items = [];
        for ($i = 0; $i < 300; $i++) { $items[] = new TestWidget(); }
        fputs(STDOUT, "ready\n");
        fgets(STDIN);
        PHP;

        $baseline_db = $this->captureSnapshot($docker_image_name, $baseline_script);
        $target_db = $this->captureSnapshot($docker_image_name, $target_script);

        $output_path = $this->createTmpFile('.json');

        $compare_cmd = new MemoryCompareCommand();
        $compare_input = new ArrayInput([
            'baseline' => $baseline_db,
            'target' => $target_db,
            '--output-format' => 'report-json',
            '--output' => $output_path,
        ]);
        $compare_input->setInteractive(false);
        $this->assertSame(0, $compare_cmd->run($compare_input, new BufferedOutput()));

        $json = file_get_contents($output_path);
        $this->assertNotFalse($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);

        $this->assertArrayHasKey('baseline', $decoded);
        $this->assertArrayHasKey('target', $decoded);
        $this->assertArrayHasKey('summary_deltas', $decoded);
        $this->assertArrayHasKey('class_deltas', $decoded);
        $this->assertArrayHasKey('findings_diff', $decoded);

        // Summary deltas should show memory increase
        $this->assertNotEmpty($decoded['summary_deltas']);

        // Class deltas should contain TestWidget
        $changed_classes = array_column($decoded['class_deltas']['changed'], 'class_name');
        $this->assertContains('TestWidget', $changed_classes);
    }
}
