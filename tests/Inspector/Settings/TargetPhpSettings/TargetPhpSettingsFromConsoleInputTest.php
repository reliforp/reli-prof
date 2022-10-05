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

namespace Reli\Inspector\Settings\TargetPhpSettings;

use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;

class TargetPhpSettingsFromConsoleInputTest extends TestCase
{
    public function testFromConsoleInput(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('php-regex')->andReturns('abc');
        $input->expects()->getOption('libpthread-regex')->andReturns('def');
        $input->expects()->getOption('php-version')->andReturns('v74');
        $input->expects()->getOption('php-path')->andReturns('ghi');
        $input->expects()->getOption('libpthread-path')->andReturns('jkl');

        $settings = (new TargetPhpSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame('abc', $settings->php_regex);
        $this->assertSame('def', $settings->libpthread_regex);
        $this->assertSame('v74', $settings->php_version);
        $this->assertSame('ghi', $settings->php_path);
        $this->assertSame('jkl', $settings->libpthread_path);
    }

    public function testFromConsoleInputDefault(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('php-regex')->andReturns(null);
        $input->expects()->getOption('libpthread-regex')->andReturns(null);
        $input->expects()->getOption('php-version')->andReturns(null);
        $input->expects()->getOption('php-path')->andReturns(null);
        $input->expects()->getOption('libpthread-path')->andReturns(null);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputPhpVersionNotSupported(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('php-regex')->andReturns(null);
        $input->expects()->getOption('libpthread-regex')->andReturns(null);
        $input->expects()->getOption('php-version')->andReturns('v56');
        $input->expects()->getOption('php-path')->andReturns(null);
        $input->expects()->getOption('libpthread-path')->andReturns(null);
        $this->expectException(TargetPhpSettingsException::class);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputPhpRegexNonString(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('php-regex')->andReturns(1);
        $input->expects()->getOption('libpthread-regex')->andReturns(null);
        $input->expects()->getOption('php-version')->andReturns(null);
        $input->expects()->getOption('php-path')->andReturns(null);
        $input->expects()->getOption('libpthread-path')->andReturns(null);
        $this->expectException(TargetPhpSettingsException::class);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputPthreadRegexNonString(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('php-regex')->andReturns(null);
        $input->expects()->getOption('libpthread-regex')->andReturns(1);
        $input->expects()->getOption('php-version')->andReturns(null);
        $input->expects()->getOption('php-path')->andReturns(null);
        $input->expects()->getOption('libpthread-path')->andReturns(null);
        $this->expectException(TargetPhpSettingsException::class);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputPhpPathNonString(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('php-regex')->andReturns(null);
        $input->expects()->getOption('libpthread-regex')->andReturns(null);
        $input->expects()->getOption('php-version')->andReturns(null);
        $input->expects()->getOption('php-path')->andReturns(1);
        $input->expects()->getOption('libpthread-path')->andReturns(null);
        $this->expectException(TargetPhpSettingsException::class);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputPthreadPathNonString(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('php-regex')->andReturns(null);
        $input->expects()->getOption('libpthread-regex')->andReturns(null);
        $input->expects()->getOption('php-version')->andReturns(null);
        $input->expects()->getOption('php-path')->andReturns(null);
        $input->expects()->getOption('libpthread-path')->andReturns(1);
        $this->expectException(TargetPhpSettingsException::class);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }
}
