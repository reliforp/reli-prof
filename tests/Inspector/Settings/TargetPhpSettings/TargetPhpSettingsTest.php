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

namespace PhpProfiler\Inspector\Settings\TargetPhpSettings;

use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PHPUnit\Framework\TestCase;

class TargetPhpSettingsTest extends TestCase
{
    public function testAlterPhpVersion(): void
    {
        $settings = new TargetPhpSettings(
            php_version: ZendTypeReader::V74,
        );
        $settings_altered = $settings->alterPhpVersion(ZendTypeReader::V81);
        $this->assertSame($settings->php_path, $settings_altered->php_path);
        $this->assertSame($settings->getDelimitedPhpRegex(), $settings_altered->getDelimitedPhpRegex());
        $this->assertSame($settings->libpthread_path, $settings_altered->libpthread_path);
        $this->assertSame($settings->getDelimitedLibPthreadRegex(), $settings_altered->getDelimitedLibPthreadRegex());
        $this->assertSame(ZendTypeReader::V81, $settings_altered->php_version);
    }

    public function testGetDelimitedPhpRegex(): void
    {
        $settings = new TargetPhpSettings(
            php_regex: 'test',
        );
        $this->assertSame('{test}', $settings->getDelimitedPhpRegex());
    }

    public function testGetDelimitedLibPthreadRegex(): void
    {
        $settings = new TargetPhpSettings(
            libpthread_regex: 'test',
        );
        $this->assertSame('{test}', $settings->getDelimitedLibPthreadRegex());
    }
}
