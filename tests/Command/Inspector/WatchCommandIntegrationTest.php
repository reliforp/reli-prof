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
use Reli\BaseTestCase;
use Reli\TargetPhpVmProvider;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
#[Group('target-version')]
class WatchCommandIntegrationTest extends BaseTestCase
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

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testWatchWithMemoryLimit(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            $data = [];
            fputs(STDOUT, "ready\n");
            // Grow memory quickly
            while (true) {
                $data[] = str_repeat("x", 10240);
                usleep(5000);
            }
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );

        $s = fgets($pipes[1]);
        $this->assertSame("ready\n", $s);

        // Build the DI container and get command
        $container_builder = new \DI\ContainerBuilder();
        $container_builder->addDefinitions(
            __DIR__ . '/../../../config/di.php',
        );
        $container = $container_builder->build();

        /** @var WatchCommand $command */
        $command = $container->get(WatchCommand::class);
        $app = new Application();
        $app->add($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--memory-limit' => '1M',
            '--oneshot' => '2',
            '--action' => ['log'],
            '--poll-interval' => '200',
            '--cooldown' => '0',
            '--php-version' => $php_version,
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString(
            '[TRIGGERED]',
            $display,
        );
        $this->assertStringContainsString(
            'memory-limit',
            $display,
        );
        $this->assertStringContainsString(
            'Monitoring stopped',
            $display,
        );

        // Should have exactly 2 trigger events (--oneshot=2)
        $triggered_count = substr_count($display, '[TRIGGERED]');
        $this->assertSame(2, $triggered_count);
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testWatchWithWatchFunction(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            fputs(STDOUT, "ready\n");
            while (true) {
                usleep(50000);
            }
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );

        $s = fgets($pipes[1]);
        $this->assertSame("ready\n", $s);

        $container_builder = new \DI\ContainerBuilder();
        $container_builder->addDefinitions(
            __DIR__ . '/../../../config/di.php',
        );
        $container = $container_builder->build();

        /** @var WatchCommand $command */
        $command = $container->get(WatchCommand::class);
        $app = new Application();
        $app->add($command);

        $tester = new CommandTester($command);
        $tester->execute([
            '-p' => (string)$pid,
            '--watch-function' => 'usleep',
            '--oneshot' => '1',
            '--action' => ['log'],
            '--poll-interval' => '200',
            '--cooldown' => '0',
            '--php-version' => $php_version,
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString(
            '[TRIGGERED]',
            $display,
        );
        $this->assertStringContainsString(
            'watch-function',
            $display,
        );
    }

    #[DataProviderExternal(TargetPhpVmProvider::class, 'allSupported')]
    public function testWatchDaemonMode(
        string $php_version,
        string $docker_image_name,
    ): void {
        $target_script = <<<'CODE'
            <?php
            $data = [];
            fputs(STDOUT, "ready\n");
            while (true) {
                $data[] = str_repeat("x", 10240);
                usleep(5000);
            }
            CODE;

        $pipes = [];
        [$this->child, $pid] = TargetPhpVmProvider::runScriptViaContainer(
            $docker_image_name,
            $target_script,
            $pipes,
        );

        $s = fgets($pipes[1]);
        $this->assertSame("ready\n", $s);

        // Run reli as a subprocess (not CommandTester) because
        // daemon mode uses Amphp EventLoop + async futures that
        // don't work well with CommandTester's synchronous execute().
        $reli_cmd = sprintf(
            'timeout 30 php %s inspector:watch'
            . ' --target-regex=source'
            . ' --memory-limit=1M'
            . ' --max-triggers=2'
            . ' --action=log'
            . ' --poll-interval=200'
            . ' --cooldown=0'
            . ' --php-version=%s'
            . ' 2>&1',
            escapeshellarg(__DIR__ . '/../../../reli'),
            escapeshellarg($php_version),
        );

        $output = shell_exec($reli_cmd);
        $this->assertNotNull($output);
        $this->assertStringContainsString(
            '[TRIGGERED]',
            $output,
        );
        $this->assertStringContainsString(
            'memory-limit',
            $output,
        );
    }

    public function testWatchNoTriggerReturnsError(): void
    {
        $container_builder = new \DI\ContainerBuilder();
        $container_builder->addDefinitions(
            __DIR__ . '/../../../config/di.php',
        );
        $container = $container_builder->build();

        /** @var WatchCommand $command */
        $command = $container->get(WatchCommand::class);
        $app = new Application();
        $app->add($command);

        $tester = new CommandTester($command);
        // No triggers specified — should fail before PID resolution
        $tester->execute([
            '-p' => '999999',
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString(
            'No triggers specified',
            $tester->getDisplay(),
        );
    }
}
