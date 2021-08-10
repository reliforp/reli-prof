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
        $input->expects()->getOption('stop-process')->andReturns('off');

        $settings = (new TraceLoopSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(20000000, $settings->sleep_nano_seconds);
        $this->assertSame(20, $settings->max_retries);
        $this->assertSame('q', $settings->cancel_key);
        $this->assertSame(false, $settings->stop_process);
    }

    public function testFromConsoleInputDefault(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('sleep-ns')->andReturns(null);
        $input->expects()->getOption('max-retries')->andReturns(null);
        $input->expects()->getOption('stop-process')->andReturns(null);
        (new TraceLoopSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputSleepNsNotInteger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('sleep-ns')->andReturns('abc');
        $input->expects()->getOption('max-retries')->andReturns(null);
        $input->expects()->getOption('stop-process')->andReturns(null);
        $this->expectException(TraceLoopSettingsException::class);
        (new TraceLoopSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputMaxRetriesNotInteger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('sleep-ns')->andReturns(null);
        $input->expects()->getOption('max-retries')->andReturns('abc');
        $input->expects()->getOption('stop-process')->andReturns(null);
        $this->expectException(TraceLoopSettingsException::class);
        (new TraceLoopSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputStopProcessNotBoolean(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('sleep-ns')->andReturns(null);
        $input->expects()->getOption('max-retries')->andReturns(null);
        $input->expects()->getOption('stop-process')->andReturns('abc');
        $this->expectException(TraceLoopSettingsException::class);
        (new TraceLoopSettingsFromConsoleInput())->createSettings($input);
    }
}
