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

namespace Reli\Inspector\Settings\PhpSpySettings;

use Mockery;
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;

class PhpSpySettingsFromConsoleInputTest extends BaseTestCase
{
    public function testDefaultValues(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('phpspy-path')->andReturns(null);
        $input->expects()->getOption('sleep-ns')->andReturns(null);
        $input->expects()->getOption('buffer-size')->andReturns(null);
        $input->expects()->getOption('rate-hz')->andReturns(null);
        $input->expects()->getOption('phpspy-args')->andReturns(null);

        $settings = (new PhpSpySettingsFromConsoleInput())->createSettings($input);

        $this->assertNull($settings->phpspy_path);
        $this->assertSame(PhpSpySettings::DEFAULT_SLEEP_NS, $settings->sleep_ns);
        $this->assertSame(PhpSpySettings::DEFAULT_BUFFER_SIZE, $settings->buffer_size);
        $this->assertSame(PhpSpySettings::DEFAULT_RATE_HZ, $settings->rate_hz);
        $this->assertNull($settings->extra_args);
    }

    public function testCustomValues(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('phpspy-path')->andReturns('/usr/bin/phpspy');
        $input->expects()->getOption('sleep-ns')->andReturns('5000000');
        $input->expects()->getOption('buffer-size')->andReturns('8192');
        $input->expects()->getOption('rate-hz')->andReturns('50');
        $input->expects()->getOption('phpspy-args')->andReturns('-c -1');

        $settings = (new PhpSpySettingsFromConsoleInput())->createSettings($input);

        $this->assertSame('/usr/bin/phpspy', $settings->phpspy_path);
        $this->assertSame(5000000, $settings->sleep_ns);
        $this->assertSame(8192, $settings->buffer_size);
        $this->assertSame(50, $settings->rate_hz);
        $this->assertSame('-c -1', $settings->extra_args);
    }

    public function testSleepNsNotInteger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('phpspy-path')->andReturns(null);
        $input->expects()->getOption('sleep-ns')->andReturns('abc');
        $input->allows()->getOption('buffer-size')->andReturns(null);
        $input->allows()->getOption('rate-hz')->andReturns(null);
        $input->allows()->getOption('phpspy-args')->andReturns(null);

        $this->expectException(PhpSpySettingsException::class);
        (new PhpSpySettingsFromConsoleInput())->createSettings($input);
    }

    public function testBufferSizeNotInteger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('phpspy-path')->andReturns(null);
        $input->expects()->getOption('sleep-ns')->andReturns(null);
        $input->expects()->getOption('buffer-size')->andReturns('xyz');
        $input->allows()->getOption('rate-hz')->andReturns(null);
        $input->allows()->getOption('phpspy-args')->andReturns(null);

        $this->expectException(PhpSpySettingsException::class);
        (new PhpSpySettingsFromConsoleInput())->createSettings($input);
    }

    public function testRateHzNotInteger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('phpspy-path')->andReturns(null);
        $input->expects()->getOption('sleep-ns')->andReturns(null);
        $input->expects()->getOption('buffer-size')->andReturns(null);
        $input->expects()->getOption('rate-hz')->andReturns('not_a_number');
        $input->allows()->getOption('phpspy-args')->andReturns(null);

        $this->expectException(PhpSpySettingsException::class);
        (new PhpSpySettingsFromConsoleInput())->createSettings($input);
    }
}
