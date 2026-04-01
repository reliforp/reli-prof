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
        $app->add($command);

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
        $app->add($command);

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
        $app->add($command);

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

    public function testNoVarReturnsError(): void
    {
        $command = $this->createCommand();
        $app = new Application();
        $app->add($command);

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
        $app->add($command);

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
