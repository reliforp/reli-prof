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
use Symfony\Component\Console\Input\InputInterface;

final class TargetPhpSettingsFromConsoleInput
{
    public function fromConsoleInput(InputInterface $input): TargetPhpSettings
    {
        $php_regex = $input->getOption('php-regex') ?? TargetPhpSettings::PHP_REGEX_DEFAULT;
        if (!is_string($php_regex)) {
            throw TargetPhpSettingsException::create(
                TargetPhpSettingsException::PHP_REGEX_IS_NOT_STRING
            );
        }

        $libpthread_regex = $input->getOption('libpthread-regex') ?? TargetPhpSettings::LIBPTHREAD_REGEX_DEFAULT;
        if (!is_string($libpthread_regex)) {
            throw TargetPhpSettingsException::create(
                TargetPhpSettingsException::LIBPTHREAD_REGEX_IS_NOT_STRING
            );
        }

        $php_version = $input->getOption('php-version') ?? TargetPhpSettings::TARGET_PHP_VERSION_DEFAULT;
        if (!in_array($php_version, ZendTypeReader::ALL_SUPPORTED_VERSIONS, true)) {
            throw TargetPhpSettingsException::create(
                TargetPhpSettingsException::TARGET_PHP_VERSION_INVALID
            );
        }
        /** @psalm-var value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version */

        $php_path = $input->getOption('php-path');
        if (!is_null($php_path) and !is_string($php_path)) {
            throw TargetPhpSettingsException::create(
                TargetPhpSettingsException::PHP_PATH_IS_NOT_STRING
            );
        }

        $libpthread_path = $input->getOption('libpthread-path');
        if (!is_null($libpthread_path) and !is_string($libpthread_path)) {
            throw TargetPhpSettingsException::create(
                TargetPhpSettingsException::LIBPTHREAD_PATH_IS_NOT_STRING
            );
        }

        return new TargetPhpSettings($php_regex, $libpthread_regex, $php_version, $php_path, $libpthread_path);
    }
}
