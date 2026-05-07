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

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Reli\BaseTestCase;
use Reli\TargetPhpVmProvider;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[Group('target-version')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class PeekVarCommandIntegrationTest extends BaseTestCase
{
    /** @var resource|null */
    private $child = null;

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
    }

    private function createCommand(): PeekVarCommand
    {
        $container_builder = new \DI\ContainerBuilder();
        $container_builder->addDefinitions(
            __DIR__ . '/../../../config/di.php',
        );
        $container = $container_builder->build();
        return $container->get(PeekVarCommand::class);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testPeekGlobalVariables(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            $GLOBALS['counter'] = 42;
            $GLOBALS['name'] = "hello_world";
            $GLOBALS['items'] = array_fill(0, 10, "x");
            $GLOBALS['flag'] = true;
            $GLOBALS['nothing'] = null;
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

        $command = $this->createCommand();
        $app = new Application();
        $app->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--var' => [
                'global::$counter',
                'global::$name',
                'global::$items',
                'global::$flag',
                'global::$nothing',
                'global::$nonexistent',
            ],
            '--php-version' => $php_version,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();

        $this->assertStringContainsString(
            'global::$counter = (int) 42',
            $display,
        );
        $this->assertStringContainsString(
            'global::$name = (string) "hello_world"',
            $display,
        );
        $this->assertStringContainsString(
            'global::$items = (array) count=10',
            $display,
        );
        $this->assertStringContainsString(
            'global::$flag = (bool) true',
            $display,
        );
        $this->assertStringContainsString(
            'global::$nothing = null',
            $display,
        );
        $this->assertStringContainsString(
            'global::$nonexistent = <not found>',
            $display,
        );
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testPeekLocalVariable(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            $local_val = 99;
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

        $command = $this->createCommand();
        $app = new Application();
        $app->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--var' => ['local::<main>()$local_val'],
            '--php-version' => $php_version,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString(
            'local::<main>()$local_val = (int) 99',
            $display,
        );
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testPeekJsonFormat(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            $GLOBALS['val'] = 123;
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

        $command = $this->createCommand();
        $app = new Application();
        $app->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--var' => ['global::$val'],
            '--format' => 'json',
            '--php-version' => $php_version,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $json = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('global::$val', $json);
        $this->assertSame('long', $json['global::$val']['type']);
        $this->assertSame(123, $json['global::$val']['value']);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testPeekMemoryVariable(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            $padding = str_repeat('x', 1024 * 1024);
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

        $command = $this->createCommand();
        $app = new Application();
        $app->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--var' => [
                'memory::memory_get_usage',
                'memory::memory_get_peak_usage',
            ],
            '--format' => 'json',
            '--php-version' => $php_version,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $json = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('memory::memory_get_usage', $json);
        $this->assertNotNull(
            $json['memory::memory_get_usage'],
            'memory::memory_get_usage must not be null'
                . ' (regression: HeapStatsReader was missing from'
                . ' the concrete VariableReader DI binding)',
        );
        $this->assertSame('long', $json['memory::memory_get_usage']['type']);
        $this->assertGreaterThan(
            1024 * 1024,
            $json['memory::memory_get_usage']['value'],
        );
        $this->assertNotNull($json['memory::memory_get_peak_usage']);
        $this->assertGreaterThanOrEqual(
            $json['memory::memory_get_usage']['value'],
            $json['memory::memory_get_peak_usage']['value'],
        );
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testPeekVarDumpFormat(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            class Foo {
                public $pub = 1;
                private $priv = "secret";
            }
            $GLOBALS['arr'] = ['a' => 1, 'b' => [10, 20]];
            $GLOBALS['obj'] = new Foo();
            $GLOBALS['s'] = "hi";
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );
        $this->assertSame("ready\n", fgets($pipes[1]));

        $command = $this->createCommand();
        (new Application())->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--var' => ['global::$arr', 'global::$obj', 'global::$s'],
            '--format' => 'var-dump',
            '--php-version' => $php_version,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        // Native: array(2) { ["a"]=> int(1) ["b"]=> array(2) { [0]=> int(10) [1]=> int(20) } }
        $this->assertStringContainsString('array(2) {', $display);
        $this->assertStringContainsString('["a"]=>', $display);
        $this->assertStringContainsString('int(1)', $display);
        $this->assertStringContainsString('int(10)', $display);
        $this->assertStringContainsString('int(20)', $display);
        $this->assertStringContainsString('object(Foo)', $display);
        $this->assertStringContainsString('["pub"]=>', $display);
        $this->assertStringContainsString('["priv":"Foo":private]=>', $display);
        $this->assertStringContainsString('string(6) "secret"', $display);
        $this->assertStringContainsString('string(2) "hi"', $display);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testPeekVarExportFormat(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            $GLOBALS['arr'] = ['a' => 1, 'b' => [10, 20]];
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );
        $this->assertSame("ready\n", fgets($pipes[1]));

        $command = $this->createCommand();
        (new Application())->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--var' => ['global::$arr'],
            '--format' => 'var-export',
            '--php-version' => $php_version,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $expected = var_export(['a' => 1, 'b' => [10, 20]], true);
        $this->assertStringContainsString($expected, $display);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testPeekPhpSerializeFormat(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            $GLOBALS['arr'] = ['a' => 1, 'b' => [10, 20]];
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );
        $this->assertSame("ready\n", fgets($pipes[1]));

        $command = $this->createCommand();
        (new Application())->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--var' => ['global::$arr'],
            '--format' => 'php-serialize',
            '--php-version' => $php_version,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $expected = serialize(['a' => 1, 'b' => [10, 20]]);
        $this->assertStringContainsString($expected, $display);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testPeekVarDumpRespectsMaxDepth(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            $GLOBALS['nested'] = ['outer' => ['inner' => ['deep' => 1]]];
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );
        $this->assertSame("ready\n", fgets($pipes[1]));

        $command = $this->createCommand();
        (new Application())->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--var' => ['global::$nested'],
            '--format' => 'var-dump',
            '--max-depth' => '1',
            '--php-version' => $php_version,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        // Outer expanded, inner shown as `...` placeholder.
        $this->assertStringContainsString('["outer"]=>', $display);
        $this->assertStringContainsString('array(1) {', $display);
        $this->assertStringNotContainsString('["deep"]=>', $display);
        $this->assertStringContainsString('...', $display);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testPeekVarDumpDetectsRecursion(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            class Node { public $next = null; }
            $GLOBALS['n'] = new Node();
            $GLOBALS['n']->next = $GLOBALS['n'];
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );
        $this->assertSame("ready\n", fgets($pipes[1]));

        $command = $this->createCommand();
        (new Application())->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--var' => ['global::$n'],
            '--format' => 'var-dump',
            '--php-version' => $php_version,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('object(Node)', $display);
        $this->assertStringContainsString('*RECURSION*', $display);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testPhpSerializeCycleRoundTripsThroughUnserialize(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            class Node { public $next = null; public $tag = "x"; }
            $GLOBALS['n'] = new Node();
            $GLOBALS['n']->next = $GLOBALS['n'];
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );
        $this->assertSame("ready\n", fgets($pipes[1]));

        $command = $this->createCommand();
        (new Application())->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--var' => ['global::$n'],
            '--format' => 'php-serialize',
            '--php-version' => $php_version,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();

        // Strip the leading "global::$n = " prefix to get just the
        // serialize payload, then feed it back through unserialize().
        $prefix = 'global::$n = ';
        $start = strpos($display, $prefix);
        $this->assertIsInt($start);
        $payload = trim(substr($display, $start + strlen($prefix)));
        $this->assertStringStartsWith('O:4:"Node":', $payload);
        $this->assertStringContainsString('r:1;', $payload);

        // Round-trip back through unserialize. Class Node doesn't exist
        // in the test process, so PHP returns __PHP_Incomplete_Class —
        // that's fine: we care that the cycle survives and the property
        // is recoverable.
        $revived = unserialize($payload);
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $revived);
        $array = (array)$revived;
        $this->assertSame('x', $array['tag']);
        // Self-reference back through `next`: identity holds.
        $this->assertSame($revived, $array['next']);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testPhpSerializeAmpersandShareSurvivesRoundTrip(
        string $php_version,
        string $docker_image_name,
    ): void {
        // Two array slots aliased to the same scalar via `&`. After the
        // round-trip the alias relationship must hold — writing through
        // one slot is visible through the other. This pins the
        // zend_reference-tracking path: the reader has to capture the
        // ref pointer before resolveIndirectAndRef collapses it, and
        // the formatter has to emit R:N; for the second occurrence.
        $target_script = <<<'CODE'
            <?php
            $x = "hello";
            $GLOBALS['arr'] = [&$x, &$x];
            fputs(STDOUT, "ready\n");
            fgets(STDIN);
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );
        $this->assertSame("ready\n", fgets($pipes[1]));

        $command = $this->createCommand();
        (new Application())->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--var' => ['global::$arr'],
            '--format' => 'php-serialize',
            '--php-version' => $php_version,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $prefix = 'global::$arr = ';
        $start = strpos($display, $prefix);
        $this->assertIsInt($start);
        $payload = trim(substr($display, $start + strlen($prefix)));

        // Native PHP serialize for [&$x, &$x] is a:2:{i:0;s:5:"hello";i:1;R:2;}
        $this->assertSame('a:2:{i:0;s:5:"hello";i:1;R:2;}', $payload);

        $revived = unserialize($payload);
        $this->assertIsArray($revived);
        $this->assertSame('hello', $revived[0]);
        $revived[0] = 'modified';
        $this->assertSame(
            'modified',
            $revived[1],
            'writing to slot 0 must be visible from slot 1 — '
                . 'the zend_reference alias is preserved',
        );
    }

    public function testNoVarReturnsError(): void
    {
        $command = $this->createCommand();
        $app = new Application();
        $app->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => '999999',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString(
            'No variables specified',
            $tester->getDisplay(),
        );
    }

    public function testInvalidVarExpressionReturnsError(): void
    {
        $command = $this->createCommand();
        $app = new Application();
        $app->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => '999999',
            '--var' => ['badscope::$foo'],
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString(
            'Unknown scope',
            $tester->getDisplay(),
        );
    }
}
