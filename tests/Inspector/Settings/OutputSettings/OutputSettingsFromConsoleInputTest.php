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

namespace Reli\Inspector\Settings\OutputSettings;

use Mockery;
use Noodlehaus\Config;
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;

class OutputSettingsFromConsoleInputTest extends BaseTestCase
{
    public function testCreateSettingsWithOutputFormat(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns('template:test');
        $input->allows()->getOption('template')->andReturns(null);
        $input->expects()->getOption('output')->andReturns(null);
        $input->allows()->getOption('rbt-timestamps')->andReturns('none');
        $input->allows()->getOption('rbt-compress')->andReturns(false);
        $config = Mockery::mock(Config::class);

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertSame('template:test', $settings->output_format);
        $this->assertSame('test', $settings->getTemplateName());
    }

    public function testCreateSettingsWithTemplateFallback(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns(null);
        $input->expects()->getOption('template')->andReturns('test');
        $input->expects()->getOption('output')->andReturns(null);
        $input->allows()->getOption('rbt-timestamps')->andReturns('none');
        $input->allows()->getOption('rbt-compress')->andReturns(false);
        $config = Mockery::mock(Config::class);

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertSame('template:test', $settings->output_format);
        $this->assertSame('test', $settings->getTemplateName());
    }

    public function testCreateSettingsFallbackToConfig(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns(null);
        $input->expects()->getOption('template')->andReturns(null);
        $input->allows()->getOption('output')->andReturns(null);
        $input->allows()->getOption('rbt-timestamps')->andReturns('none');
        $input->allows()->getOption('rbt-compress')->andReturns(false);
        $config = Mockery::mock(Config::class);
        $config->expects()->get('output.template.default')->andReturns('test');

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertSame('template:test', $settings->output_format);
        $this->assertSame('test', $settings->getTemplateName());
    }

    public function testCreateSettingsFallbackToConfigReturnsNull(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns(null);
        $input->expects()->getOption('template')->andReturns(null);
        $input->allows()->getOption('output')->andReturns(null);
        $config = Mockery::mock(Config::class);
        $config->expects()->get('output.template.default')->andReturns(null);
        $this->expectException(OutputSettingsException::class);

        (new OutputSettingsFromConsoleInput($config))->createSettings($input);
    }

    public function testCreateSettingsInvalidOutput(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->allows()->getOption('output-format')->andReturns('template:test');
        $input->allows()->getOption('template')->andReturns(null);
        $input->expects()->getOption('output')->andReturns(123);
        $config = Mockery::mock(Config::class);
        $this->expectException(OutputSettingsException::class);

        (new OutputSettingsFromConsoleInput($config))->createSettings($input);
    }

    public function testCreateSettingsRbt(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns('rbt');
        $input->allows()->getOption('template')->andReturns(null);
        $input->expects()->getOption('output')->andReturns(null);
        $input->allows()->getOption('rbt-timestamps')->andReturns('none');
        $input->allows()->getOption('rbt-compress')->andReturns(false);
        $config = Mockery::mock(Config::class);

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertTrue($settings->isBinaryTrace());
        $this->assertFalse($settings->isTemplate());
    }

    public function testCreateSettingsRbtBundled(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns('rbt-bundled');
        $input->allows()->getOption('template')->andReturns(null);
        $input->expects()->getOption('output')->andReturns(null);
        $input->allows()->getOption('rbt-timestamps')->andReturns('none');
        $input->allows()->getOption('rbt-compress')->andReturns(false);
        $config = Mockery::mock(Config::class);

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertTrue($settings->isBinaryTraceBundled());
        $this->assertFalse($settings->isTemplate());
    }

    public function testCreateSettingsAutoDetectsRbtFromOutputExtension(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns(null);
        $input->expects()->getOption('template')->andReturns(null);
        $input->expects()->getOption('output')->andReturns('/tmp/trace.rbt');
        $input->allows()->getOption('rbt-timestamps')->andReturns('none');
        $input->allows()->getOption('rbt-compress')->andReturns(false);
        $config = Mockery::mock(Config::class);

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertSame('rbt', $settings->output_format);
        $this->assertTrue($settings->isBinaryTrace());
        $this->assertFalse($settings->isTemplate());
        $this->assertSame('/tmp/trace.rbt', $settings->output_path);
    }

    public function testCreateSettingsAutoDetectsRbtExtensionCaseInsensitive(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns(null);
        $input->expects()->getOption('template')->andReturns(null);
        $input->expects()->getOption('output')->andReturns('/tmp/Trace.RBT');
        $input->allows()->getOption('rbt-timestamps')->andReturns('none');
        $input->allows()->getOption('rbt-compress')->andReturns(false);
        $config = Mockery::mock(Config::class);

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertTrue($settings->isBinaryTrace());
    }

    public function testCreateSettingsExplicitFormatWinsOverRbtExtension(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns('template:phpspy');
        $input->allows()->getOption('template')->andReturns(null);
        $input->expects()->getOption('output')->andReturns('/tmp/trace.rbt');
        $input->allows()->getOption('rbt-timestamps')->andReturns('none');
        $input->allows()->getOption('rbt-compress')->andReturns(false);
        $config = Mockery::mock(Config::class);

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertSame('template:phpspy', $settings->output_format);
        $this->assertFalse($settings->isBinaryTrace());
    }

    public function testCreateSettingsRbtGzExtensionDoesNotAutoDetect(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns(null);
        $input->expects()->getOption('template')->andReturns(null);
        $input->expects()->getOption('output')->andReturns('/tmp/trace.rbt.gz');
        $input->allows()->getOption('rbt-timestamps')->andReturns('none');
        $input->allows()->getOption('rbt-compress')->andReturns(false);
        $config = Mockery::mock(Config::class);
        $config->expects()->get('output.template.default')->andReturns('phpspy');

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertSame('template:phpspy', $settings->output_format);
        $this->assertFalse($settings->isBinaryTrace());
    }

    public function testCreateSettingsNonRbtExtensionFallsThroughToConfigDefault(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns(null);
        $input->expects()->getOption('template')->andReturns(null);
        $input->expects()->getOption('output')->andReturns('/tmp/trace.txt');
        $input->allows()->getOption('rbt-timestamps')->andReturns('none');
        $input->allows()->getOption('rbt-compress')->andReturns(false);
        $config = Mockery::mock(Config::class);
        $config->expects()->get('output.template.default')->andReturns('phpspy');

        $settings = (new OutputSettingsFromConsoleInput($config))->createSettings($input);
        $this->assertSame('template:phpspy', $settings->output_format);
        $this->assertFalse($settings->isBinaryTrace());
    }
}
