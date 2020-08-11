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

namespace PhpProfiler\Inspector\Settings\TraceLoopSettings;

use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;

class TraceLoopSettingsFromConsoleInputTest extends TestCase
{
    public function testFromConsoleInput(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('sleep-ns')->andReturns(20000000);
        $input->expects()->getOption('max-retries')->andReturns(20);

        $settings = (new TraceLoopSettingsFromConsoleInput())->fromConsoleInput($input);

        $this->assertSame(20000000, $settings->sleep_nano_seconds);
        $this->assertSame(20, $settings->max_retries);
        $this->assertSame('q', $settings->cancel_key);
    }

    public function testFromConsoleInputDefault(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('sleep-ns')->andReturns(null);
        $input->expects()->getOption('max-retries')->andReturns(null);
        $settings = (new TraceLoopSettingsFromConsoleInput())->fromConsoleInput($input);
    }

    public function testFromConsoleInputSleepNsNotInteger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('sleep-ns')->andReturns('abc');
        $input->expects()->getOption('max-retries')->andReturns(null);
        $this->expectException(TraceLoopSettingsException::class);
        $settings = (new TraceLoopSettingsFromConsoleInput())->fromConsoleInput($input);
    }

    public function testFromConsoleInputMaxRetriesNotInteger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('sleep-ns')->andReturns(null);
        $input->expects()->getOption('max-retries')->andReturns('abc');
        $this->expectException(TraceLoopSettingsException::class);
        $settings = (new TraceLoopSettingsFromConsoleInput())->fromConsoleInput($input);
    }
}
