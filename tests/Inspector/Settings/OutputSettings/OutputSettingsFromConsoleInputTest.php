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

namespace PhpProfiler\Inspector\Settings\OutputSettings;

use Mockery;
use Noodlehaus\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;

class OutputSettingsFromConsoleInputTest extends TestCase
{
    public function testCreateSettings(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('template')->andReturns('test');
        $input->expects()->getOption('output')->andReturns(null);
        $config = Mockery::mock(Config::class);
        $config->expects()->get()->never();

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertSame('test', $settings->template_name);
    }

    public function testCreateSettingsFallbackToConfig(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('template')->andReturns(null);
        $input->expects()->getOption('output')->andReturns(null);
        $config = Mockery::mock(Config::class);
        $config->expects()->get('output.template.default')->andReturns('test');

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertSame('test', $settings->template_name);
    }

    public function testCreateSettingsFallbackToConfigReturnsNull(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('template')->andReturns(null);
        $input->expects()->getOption('output')->andReturns(null);
        $config = Mockery::mock(Config::class);
        $config->expects()->get('output.template.default')->andReturns(null);
        $this->expectException(OutputSettingsException::class);

        (new OutputSettingsFromConsoleInput($config))->createSettings($input);
    }

    public function testCreateSettingsInvalidOutput(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('template')->andReturns('test');
        $input->expects()->getOption('output')->andReturns(123);
        $config = Mockery::mock(Config::class);
        $config->expects()->get()->never();
        $this->expectException(OutputSettingsException::class);

        (new OutputSettingsFromConsoleInput($config))->createSettings($input);
    }
}
