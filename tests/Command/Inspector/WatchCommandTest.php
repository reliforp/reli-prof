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

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class WatchCommandTest extends TestCase
{
    private static function makeCommand(): WatchCommand
    {
        $container_builder = new \DI\ContainerBuilder();
        $container_builder->addDefinitions(
            __DIR__ . '/../../../config/di.php',
        );
        $container = $container_builder->build();
        return $container->get(WatchCommand::class);
    }

    public function testCommandIsRegistered(): void
    {
        $cmd = self::makeCommand();
        $this->assertSame('inspector:watch', $cmd->getName());
    }

    public function testDescriptionIsSet(): void
    {
        $cmd = self::makeCommand();
        $desc = $cmd->getDescription();
        $this->assertNotEmpty($desc);
        $this->assertStringContainsString('monitor', $desc);
    }

    public function testHasTriggerOptions(): void
    {
        $cmd = self::makeCommand();
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('memory-usage'));
        $this->assertTrue($def->hasOption('memory-growth-rate'));
        $this->assertTrue($def->hasOption('memory-peak-watch'));
        $this->assertTrue($def->hasOption('watch-function'));
        $this->assertTrue($def->hasOption('trace-depth-limit'));
        $this->assertTrue($def->hasOption('watch-var'));
    }

    public function testHasActionOptions(): void
    {
        $cmd = self::makeCommand();
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('action'));
        $this->assertTrue($def->hasOption('action-exec-command'));
        $this->assertTrue($def->hasOption('action-output-dir'));
        $this->assertTrue($def->hasOption('log-file'));
        $this->assertTrue($def->hasOption('memory-output-format'));
    }

    public function testHasRateLimitOptions(): void
    {
        $cmd = self::makeCommand();
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('poll-interval'));
        $this->assertTrue($def->hasOption('cooldown'));
        $this->assertTrue($def->hasOption('max-triggers'));
        $this->assertTrue($def->hasOption('oneshot'));
        $this->assertTrue($def->hasOption('max-triggers-per-hour'));
        $this->assertTrue($def->hasOption('max-dump-size'));
        $this->assertTrue($def->hasOption('backoff-multiplier'));
        $this->assertTrue($def->hasOption('backoff-max'));
        $this->assertTrue($def->hasOption('status-interval'));
        $this->assertTrue($def->hasOption('quiet-watch'));
    }

    public function testHasProcessTargetOptions(): void
    {
        $cmd = self::makeCommand();
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('pid'));
        $this->assertTrue($def->hasOption('target-regex'));
        $this->assertTrue($def->hasOption('no-cache'));
    }

    public function testOptionDefaults(): void
    {
        $cmd = self::makeCommand();
        $def = $cmd->getDefinition();
        // --action's CLI-level default is empty: the implicit
        // "memory-dump if nothing else specified" lives in
        // WatchSettingsFromConsoleInput::createSettings, gated on
        // no --on-enter / --on-exit being set.
        $action_opt = $def->getOption('action');
        $this->assertSame([], $action_opt->getDefault());

        $output_dir = $def->getOption('action-output-dir');
        $this->assertNull($output_dir->getDefault());

        $format = $def->getOption('memory-output-format');
        $this->assertSame('json', $format->getDefault());
    }
}
