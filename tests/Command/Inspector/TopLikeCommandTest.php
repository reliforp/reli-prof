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
class TopLikeCommandTest extends TestCase
{
    private static function makeCommand(): TopLikeCommand
    {
        $container_builder = new \DI\ContainerBuilder();
        $container_builder->addDefinitions(
            __DIR__ . '/../../../config/di.php',
        );
        $container = $container_builder->build();
        return $container->get(TopLikeCommand::class);
    }

    public function testCommandIsRegistered(): void
    {
        $cmd = self::makeCommand();
        $this->assertSame('inspector:top', $cmd->getName());
    }

    public function testDescriptionIsSet(): void
    {
        $cmd = self::makeCommand();
        $desc = $cmd->getDescription();
        $this->assertNotEmpty($desc);
        $this->assertStringContainsString('top', $desc);
    }

    public function testHasSingleProcessOptions(): void
    {
        $cmd = self::makeCommand();
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('pid'));
        $this->assertTrue($def->hasArgument('cmd'));
        $this->assertTrue($def->hasArgument('args'));
    }

    public function testHasDaemonModeOptions(): void
    {
        $cmd = self::makeCommand();
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('target-regex'));
        $this->assertTrue($def->hasOption('threads'));
    }

    public function testHasTraceOptions(): void
    {
        $cmd = self::makeCommand();
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('depth'));
        $this->assertTrue($def->hasOption('sleep-ns'));
        $this->assertTrue($def->hasOption('max-retries'));
        $this->assertTrue($def->hasOption('stop-process'));
        $this->assertTrue($def->hasOption('bulk-stack-copy'));
    }

    public function testHasPhpTargetOptions(): void
    {
        $cmd = self::makeCommand();
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('php-regex'));
        $this->assertTrue($def->hasOption('php-version'));
        $this->assertTrue($def->hasOption('php-path'));
        $this->assertTrue($def->hasOption('libpthread-regex'));
    }

    public function testHasNoCacheOption(): void
    {
        $cmd = self::makeCommand();
        $def = $cmd->getDefinition();
        $this->assertTrue($def->hasOption('no-cache'));
        $this->assertFalse($def->getOption('no-cache')->acceptValue());
    }

    public function testPidOptionHasShortcut(): void
    {
        $cmd = self::makeCommand();
        $opt = $cmd->getDefinition()->getOption('pid');
        $this->assertSame('p', $opt->getShortcut());
    }

    public function testTargetRegexOptionHasShortcut(): void
    {
        $cmd = self::makeCommand();
        $opt = $cmd->getDefinition()->getOption('target-regex');
        $this->assertSame('P', $opt->getShortcut());
    }
}
