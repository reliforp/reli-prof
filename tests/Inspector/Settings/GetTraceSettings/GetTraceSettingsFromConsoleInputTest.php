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
        $input->expects()->getOption('bulk-stack-copy')->andReturns(null);
        $input->allows()->hasParameterOption(['--bulk-stack-copy'])->andReturns(false);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(10, $settings->depth);
        $this->assertFalse($settings->with_native_trace);
        $this->assertNull($settings->bulk_stack_copy_max_size);
    }

    public function testFromConsoleInputDefault(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns(null);
        $input->expects()->getOption('with-native-trace')->andReturns(false);
        $input->expects()->getOption('native-trace-anytime')->andReturns(false);
        $input->expects()->getOption('bulk-stack-copy')->andReturns(null);
        $input->allows()->hasParameterOption(['--bulk-stack-copy'])->andReturns(false);
        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);
        $this->assertSame(PHP_INT_MAX, $settings->depth);
        $this->assertFalse($settings->with_native_trace);
        $this->assertNull($settings->bulk_stack_copy_max_size);
    }

    public function testFromConsoleInputDepthNotInteger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns('abc');
        $input->allows()->getOption('with-native-trace')->andReturns(false);
        $input->allows()->getOption('native-trace-anytime')->andReturns(false);
        $input->allows()->getOption('bulk-stack-copy')->andReturns(null);
        $input->allows()->hasParameterOption(['--bulk-stack-copy'])->andReturns(false);
        $this->expectException(GetTraceSettingsException::class);
        (new GetTraceSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputWithNativeTrace(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns(null);
        $input->expects()->getOption('with-native-trace')->andReturns(true);
        $input->expects()->getOption('native-trace-anytime')->andReturns(false);
        $input->expects()->getOption('bulk-stack-copy')->andReturns(null);
        $input->allows()->hasParameterOption(['--bulk-stack-copy'])->andReturns(false);
        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);
        $this->assertTrue($settings->with_native_trace);
        $this->assertFalse($settings->native_trace_anytime);
        $this->assertNull($settings->bulk_stack_copy_max_size);
    }

    public function testFromConsoleInputNativeTraceAnytime(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns(null);
        $input->expects()->getOption('with-native-trace')->andReturns(false);
        $input->expects()->getOption('native-trace-anytime')->andReturns(true);
        $input->expects()->getOption('bulk-stack-copy')->andReturns(null);
        $input->allows()->hasParameterOption(['--bulk-stack-copy'])->andReturns(false);
        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);
        $this->assertTrue($settings->with_native_trace); // implied by anytime
        $this->assertTrue($settings->native_trace_anytime);
        $this->assertNull($settings->bulk_stack_copy_max_size);
    }

    public function testFromConsoleInputBulkStackCopyFlag(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns(null);
        $input->expects()->getOption('with-native-trace')->andReturns(false);
        $input->expects()->getOption('native-trace-anytime')->andReturns(false);
        $input->expects()->getOption('bulk-stack-copy')->andReturns(null);
        $input->allows()->hasParameterOption(['--bulk-stack-copy'])->andReturns(true);
        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);
        $this->assertSame(GetTraceSettings::BULK_STACK_COPY_DEFAULT_MAX_SIZE, $settings->bulk_stack_copy_max_size);
    }

    public function testFromConsoleInputBulkStackCopyWithSize(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns(null);
        $input->expects()->getOption('with-native-trace')->andReturns(false);
        $input->expects()->getOption('native-trace-anytime')->andReturns(false);
        $input->expects()->getOption('bulk-stack-copy')->andReturns('128K');
        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);
        $this->assertSame(128 * 1024, $settings->bulk_stack_copy_max_size);
    }

    public function testFromConsoleInputBulkStackCopyInvalidSize(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns(null);
        $input->allows()->getOption('with-native-trace')->andReturns(false);
        $input->allows()->getOption('native-trace-anytime')->andReturns(false);
        $input->expects()->getOption('bulk-stack-copy')->andReturns('abc');
        $this->expectException(GetTraceSettingsException::class);
        (new GetTraceSettingsFromConsoleInput())->createSettings($input);
    }
}
