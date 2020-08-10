<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Settings\DaemonSettings;

use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;

class DaemonSettingsTest extends TestCase
{
    public function testFromConsoleInput(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('threads')->andReturns(4);
        $input->expects()->getOption('target-regex')->andReturns('regex');
        $settings = DaemonSettings::fromConsoleInput($input);

        $this->assertSame('{regex}', $settings->target_regex);
        $this->assertSame(4, $settings->threads);
    }

    public function testFromConsoleInputDefault(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('threads')->andReturns(null);
        $input->expects()->getOption('target-regex')->andReturns(null);
        $settings = DaemonSettings::fromConsoleInput($input);
    }

    public function testFromConsoleInputThreadsNotInteger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('threads')->andReturns('abc');
        $input->expects()->getOption('target-regex')->andReturns(null);
        $this->expectException(DaemonInspectorSettingsException::class);
        $settings = DaemonSettings::fromConsoleInput($input);
    }

    public function testFromConsoleInputTargetRegexNotString(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('threads')->andReturns(null);
        $input->expects()->getOption('target-regex')->andReturns(1);
        $this->expectException(DaemonInspectorSettingsException::class);
        $settings = DaemonSettings::fromConsoleInput($input);
    }
}
