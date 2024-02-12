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
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;

class TargetPhpSettingsFromConsoleInputTest extends BaseTestCase
{
    public function testFromConsoleInput(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('php-regex')->andReturns('abc');
        $input->expects()->getOption('libpthread-regex')->andReturns('def');
        $input->expects()->getOption('php-version')->andReturns('v74');
        $input->expects()->getOption('php-path')->andReturns('ghi');
        $input->expects()->getOption('libpthread-path')->andReturns('jkl');
        $input->expects()->getOption('zts-globals-regex')->andReturns('mno');

        $settings = (new TargetPhpSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame('abc', $settings->php_regex);
        $this->assertSame('def', $settings->libpthread_regex);
        $this->assertSame('v74', $settings->php_version);
        $this->assertSame('ghi', $settings->php_path);
        $this->assertSame('jkl', $settings->libpthread_path);
        $this->assertSame('mno', $settings->zts_globals_regex);
    }

    public function testFromConsoleInputDefault(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('php-regex')->andReturns(null);
        $input->expects()->getOption('libpthread-regex')->andReturns(null);
        $input->expects()->getOption('php-version')->andReturns(null);
        $input->expects()->getOption('php-path')->andReturns(null);
        $input->expects()->getOption('libpthread-path')->andReturns(null);
        $input->expects()->getOption('zts-globals-regex')->andReturns(null);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputPhpVersionNotSupported(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->allows()->getOption('php-regex')->andReturns(null);
        $input->allows()->getOption('libpthread-regex')->andReturns(null);
        $input->expects()->getOption('php-version')->andReturns('v56');
        $input->allows()->getOption('php-path')->andReturns(null);
        $input->allows()->getOption('libpthread-path')->andReturns(null);
        $input->allows()->getOption('zts-globals-regex')->andReturns(null);
        $this->expectException(TargetPhpSettingsException::class);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputPhpRegexNonString(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('php-regex')->andReturns(1);
        $input->allows()->getOption('libpthread-regex')->andReturns(null);
        $input->allows()->getOption('php-version')->andReturns(null);
        $input->allows()->getOption('php-path')->andReturns(null);
        $input->allows()->getOption('libpthread-path')->andReturns(null);
        $input->allows()->getOption('zts-globals-regex')->andReturns(null);
        $this->expectException(TargetPhpSettingsException::class);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputPthreadRegexNonString(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->allows()->getOption('php-regex')->andReturns(null);
        $input->expects()->getOption('libpthread-regex')->andReturns(1);
        $input->allows()->getOption('php-version')->andReturns(null);
        $input->allows()->getOption('php-path')->andReturns(null);
        $input->allows()->getOption('libpthread-path')->andReturns(null);
        $input->allows()->getOption('zts-globals-regex')->andReturns(null);
        $this->expectException(TargetPhpSettingsException::class);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputPhpPathNonString(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->allows()->getOption('php-regex')->andReturns(null);
        $input->allows()->getOption('libpthread-regex')->andReturns(null);
        $input->allows()->getOption('php-version')->andReturns(null);
        $input->expects()->getOption('php-path')->andReturns(1);
        $input->allows()->getOption('libpthread-path')->andReturns(null);
        $input->allows()->getOption('zts-globals-regex')->andReturns(null);
        $this->expectException(TargetPhpSettingsException::class);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputPthreadPathNonString(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->allows()->getOption('php-regex')->andReturns(null);
        $input->allows()->getOption('libpthread-regex')->andReturns(null);
        $input->allows()->getOption('php-version')->andReturns(null);
        $input->allows()->getOption('php-path')->andReturns(null);
        $input->expects()->getOption('libpthread-path')->andReturns(1);
        $input->allows()->getOption('zts-globals-regex')->andReturns(null);
        $this->expectException(TargetPhpSettingsException::class);
        (new TargetPhpSettingsFromConsoleInput())->createSettings($input);
    }
}
