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

namespace Reli\Inspector\Settings\GetTraceSettings;

use Mockery;
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;

class GetTraceSettingsFromConsoleInputTest extends BaseTestCase
{
    public function testFromConsoleInput(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns(10);
        $input->expects()->getOption('with-native-trace')->andReturns(false);
        $input->expects()->getOption('native-trace-anytime')->andReturns(false);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(10, $settings->depth);
        $this->assertFalse($settings->with_native_trace);
    }

    public function testFromConsoleInputDefault(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns(null);
        $input->expects()->getOption('with-native-trace')->andReturns(false);
        $input->expects()->getOption('native-trace-anytime')->andReturns(false);
        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);
        $this->assertSame(PHP_INT_MAX, $settings->depth);
        $this->assertFalse($settings->with_native_trace);
    }

    public function testFromConsoleInputDepthNotInteger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns('abc');
        $input->allows()->getOption('with-native-trace')->andReturns(false);
        $input->allows()->getOption('native-trace-anytime')->andReturns(false);
        $this->expectException(GetTraceSettingsException::class);
        (new GetTraceSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputWithNativeTrace(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns(null);
        $input->expects()->getOption('with-native-trace')->andReturns(true);
        $input->expects()->getOption('native-trace-anytime')->andReturns(false);
        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);
        $this->assertTrue($settings->with_native_trace);
        $this->assertFalse($settings->native_trace_anytime);
    }

    public function testFromConsoleInputNativeTraceAnytime(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns(null);
        $input->expects()->getOption('with-native-trace')->andReturns(false);
        $input->expects()->getOption('native-trace-anytime')->andReturns(true);
        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);
        $this->assertTrue($settings->with_native_trace); // implied by anytime
        $this->assertTrue($settings->native_trace_anytime);
    }
}
