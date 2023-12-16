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

namespace Reli\Inspector\Settings\MemoryProfilerSettings;

use Mockery;
use PHPUnit\Framework\TestCase;
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;

class MemoryProfilerSettingsFromConsoleInputTest extends BaseTestCase
{
    public function testFromConsoleInput()
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('stop-process')->andReturns(true)->atLeast()->once();
        $input->expects()->getOption('pretty-print')->andReturns(false)->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-file')->andReturns('abc.php')->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-line')->andReturns(20)->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-max-depth')->andReturns(512)->atLeast()->once();

        $settings = (new MemoryProfilerSettingsFromConsoleInput())->createSettings($input);

        $this->assertTrue($settings->stop_process);
        $this->assertFalse($settings->pretty_print);
        $this->assertSame('abc.php', $settings->memory_exhaustion_error_details->file);
        $this->assertSame(20, $settings->memory_exhaustion_error_details->line);
        $this->assertSame(512, $settings->memory_exhaustion_error_details->max_challenge_depth);
    }

    public function testFromConsoleInputDepthNotInteger()
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('stop-process')->andReturns(true)->zeroOrMoreTimes();
        $input->expects()->getOption('pretty-print')->andReturns(false)->zeroOrMoreTimes();
        $input->expects()->getOption('memory-limit-error-file')->andReturns('abc.php')->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-line')->andReturns(20)->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-max-depth')->andReturns('abc');
        $this->expectException(MemoryProfilerSettingsException::class);
        $this->expectExceptionCode(
            MemoryProfilerSettingsException::MEMORY_LIMIT_ERROR_MAX_DEPTH_IS_NOT_POSITIVE_INTEGER
        );
        (new MemoryProfilerSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputDepthNotPositive()
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('stop-process')->andReturns(true)->zeroOrMoreTimes();
        $input->expects()->getOption('pretty-print')->andReturns(false)->zeroOrMoreTimes();
        $input->expects()->getOption('memory-limit-error-file')->andReturns('abc.php')->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-line')->andReturns(20)->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-max-depth')->andReturns(-1);
        $this->expectException(MemoryProfilerSettingsException::class);
        $this->expectExceptionCode(
            MemoryProfilerSettingsException::MEMORY_LIMIT_ERROR_MAX_DEPTH_IS_NOT_POSITIVE_INTEGER
        );
        (new MemoryProfilerSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputLineNotInteger()
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('stop-process')->andReturns(true)->zeroOrMoreTimes();
        $input->expects()->getOption('pretty-print')->andReturns(false)->zeroOrMoreTimes();
        $input->expects()->getOption('memory-limit-error-file')->andReturns('abc.php')->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-line')->andReturns('abc')->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-max-depth')->andReturns(512)->zeroOrMoreTimes();
        $this->expectException(MemoryProfilerSettingsException::class);
        $this->expectExceptionCode(
            MemoryProfilerSettingsException::MEMORY_LIMIT_ERROR_LINE_IS_NOT_INTEGER
        );
        (new MemoryProfilerSettingsFromConsoleInput())->createSettings($input);
    }
}
