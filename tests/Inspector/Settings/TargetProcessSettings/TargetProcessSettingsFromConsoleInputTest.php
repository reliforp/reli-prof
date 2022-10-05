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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;

class TargetProcessSettingsFromConsoleInputTest extends TestCase
{
    public function testFromConsoleInput(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('pid')->andReturns(10);
        $input->expects()->getArgument('cmd')->andReturns(null);

        $settings = (new TargetProcessSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(10, $settings->pid);
    }

    public function testFromConsoleInputPidNotSpecified(): void
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
        $input->expects()->getArgument('cmd')->andReturns(null);
        $this->expectException(TargetProcessSettingsException::class);
        (new TargetProcessSettingsFromConsoleInput())->createSettings($input);
    }
}
