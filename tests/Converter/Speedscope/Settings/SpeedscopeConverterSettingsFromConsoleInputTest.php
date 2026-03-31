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

namespace Reli\Converter\Speedscope\Settings;

use Mockery;
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;

class SpeedscopeConverterSettingsFromConsoleInputTest extends BaseTestCase
{
    public function testCreateSettingsIgnore(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('utf8-errors')->andReturns('ignore');

        $settings = (new SpeedscopeConverterSettingsFromConsoleInput())->createSettings($input);
        $this->assertSame(Utf8ErrorHandlingType::Ignore, $settings->utf8_error_handling_type);
    }

    public function testCreateSettingsSubstitute(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('utf8-errors')->andReturns('substitute');

        $settings = (new SpeedscopeConverterSettingsFromConsoleInput())->createSettings($input);
        $this->assertSame(Utf8ErrorHandlingType::Substitute, $settings->utf8_error_handling_type);
    }

    public function testCreateSettingsFail(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('utf8-errors')->andReturns('fail');

        $settings = (new SpeedscopeConverterSettingsFromConsoleInput())->createSettings($input);
        $this->assertSame(Utf8ErrorHandlingType::Fail, $settings->utf8_error_handling_type);
    }

    public function testCreateSettingsUnsupportedValueThrowsException(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('utf8-errors')->andReturns('invalid_value');

        $this->expectException(SpeedscopeConverterSettingsException::class);
        (new SpeedscopeConverterSettingsFromConsoleInput())->createSettings($input);
    }
}
