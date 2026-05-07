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

class PhpSpyOutputSettingsFromConsoleInputTest extends BaseTestCase
{
    public function testCreateSettingsDefaults(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns('phpspy');
        $input->expects()->getOption('output')->andReturns(null);
        $input->expects()->getOption('rbt-timestamps')->andReturns('none');
        $input->expects()->getOption('rbt-compress')->andReturns(false);

        $settings = (new PhpSpyOutputSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame('phpspy', $settings->output_format);
        $this->assertNull($settings->output_path);
        $this->assertSame('none', $settings->rbt_timestamps);
        $this->assertFalse($settings->rbt_compress);
        $this->assertFalse($settings->isBinaryTrace());
        $this->assertFalse($settings->isBinaryTraceBundled());
    }

    public function testCreateSettingsRbtBundledWithDeltaAndCompress(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns('rbt-bundled');
        $input->expects()->getOption('output')->andReturns('/tmp/out.rbt.gz');
        $input->expects()->getOption('rbt-timestamps')->andReturns('delta');
        $input->expects()->getOption('rbt-compress')->andReturns(true);

        $settings = (new PhpSpyOutputSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame('rbt-bundled', $settings->output_format);
        $this->assertSame('/tmp/out.rbt.gz', $settings->output_path);
        $this->assertTrue($settings->hasRbtTimestamps());
        $this->assertTrue($settings->rbt_compress);
        $this->assertTrue($settings->isBinaryTraceBundled());
    }

    public function testCreateSettingsPerWorkerRbt(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns('rbt');
        $input->expects()->getOption('output')->andReturns('/tmp/traces');
        $input->expects()->getOption('rbt-timestamps')->andReturns('none');
        $input->expects()->getOption('rbt-compress')->andReturns(false);

        $settings = (new PhpSpyOutputSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame('rbt', $settings->output_format);
        $this->assertTrue($settings->isBinaryTrace());
        $this->assertFalse($settings->isBinaryTraceBundled());
    }

    public function testCreateSettingsHandlesNullFormatAsPhpspy(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('output-format')->andReturns(null);
        $input->expects()->getOption('output')->andReturns(null);
        $input->expects()->getOption('rbt-timestamps')->andReturns(null);
        $input->expects()->getOption('rbt-compress')->andReturns(false);

        $settings = (new PhpSpyOutputSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame('phpspy', $settings->output_format);
        $this->assertSame('none', $settings->rbt_timestamps);
    }
}
