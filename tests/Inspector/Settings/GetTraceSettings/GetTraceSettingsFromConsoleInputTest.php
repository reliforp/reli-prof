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
        $input->expects()->getOption('start-with-trigger')->andReturns(false);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(10, $settings->depth);
    }

    public function testFromConsoleInputDefault(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns(null);
        $input->expects()->getOption('start-with-trigger')->andReturns(false);
        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);
        $this->assertSame(PHP_INT_MAX, $settings->depth);
    }

    public function testFromConsoleInputDepthNotInteger(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('depth')->andReturns('abc');
        $this->expectException(GetTraceSettingsException::class);
        (new GetTraceSettingsFromConsoleInput())->createSettings($input);
    }
}
