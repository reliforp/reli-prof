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

namespace Reli\Inspector\Settings\TargetProcessSettings;

use Mockery;
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;

class TargetProcessSettingsFromConsoleInputTest extends BaseTestCase
{
    public function testFromConsoleInput(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('pid')->andReturns(10);
        $input->expects()->getArgument('cmd')->andReturns(null);

        $settings = (new TargetProcessSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(10, $settings->pid);
    }

    public function testFromConsoleInputTargetNotSpecified(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('pid')->andReturns(null);
        $input->expects()->getArgument('cmd')->andReturns(null);
        $this->expectException(TargetProcessSettingsException::class);
        (new TargetProcessSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputPidNotInterger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('pid')->andReturns('abc');
        $input->allows()->getArgument('cmd')->andReturns(null);
        $this->expectException(TargetProcessSettingsException::class);
        (new TargetProcessSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputCommand(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('pid')->andReturns(null);
        $input->expects()->getArgument('cmd')->andReturns('php');
        $input->expects()->getArgument('args')->andReturns(['-r', 'sleep(3);']);
        $input->expects()->getOption('child-stdout')->andReturns(null);
        $input->expects()->getOption('child-stderr')->andReturns(null);

        $settings = (new TargetProcessSettingsFromConsoleInput())->createSettings($input);

        $this->assertNull($settings->pid);
        $this->assertSame('php', $settings->command);
        $this->assertSame(['-r', 'sleep(3);'], $settings->arguments);
        $this->assertNull($settings->child_stdout);
        $this->assertNull($settings->child_stderr);
    }

    public function testFromConsoleInputCommandWithChildOutput(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('pid')->andReturns(null);
        $input->expects()->getArgument('cmd')->andReturns('php');
        $input->expects()->getArgument('args')->andReturns([]);
        $input->expects()->getOption('child-stdout')->andReturns('/tmp/child.out');
        $input->expects()->getOption('child-stderr')->andReturns('/tmp/child.err');

        $settings = (new TargetProcessSettingsFromConsoleInput())->createSettings($input);

        $this->assertNull($settings->pid);
        $this->assertSame('php', $settings->command);
        $this->assertSame('/tmp/child.out', $settings->child_stdout);
        $this->assertSame('/tmp/child.err', $settings->child_stderr);
    }
}
