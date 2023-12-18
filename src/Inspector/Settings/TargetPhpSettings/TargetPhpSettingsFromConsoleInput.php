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

use Reli\Lib\PhpInternals\ZendTypeReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function in_array;
use function is_null;
use function is_string;

final class TargetPhpSettingsFromConsoleInput
{
    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'php-regex',
                null,
                InputOption::VALUE_OPTIONAL,
                'regex to find the php binary loaded in the target process'
            )
            ->addOption(
                'libpthread-regex',
                null,
                InputOption::VALUE_OPTIONAL,
                'regex to find the libpthread.so loaded in the target process'
            )
            ->addOption(
                'php-version',
                null,
                InputOption::VALUE_OPTIONAL,
                'php version (auto|v7[0-4]|v8[0123]) of the target (default: auto)'
            )
            ->addOption(
                'php-path',
                null,
                InputOption::VALUE_OPTIONAL,
                'path to the php binary (only needed in tracing chrooted ZTS target)'
            )
            ->addOption(
                'libpthread-path',
                null,
                InputOption::VALUE_OPTIONAL,
                'path to the libpthread.so (only needed in tracing chrooted ZTS target)'
            )
        ;
    }

    public function createSettings(InputInterface $input): TargetPhpSettings
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
        if ($php_version !== 'auto' and !in_array($php_version, ZendTypeReader::ALL_SUPPORTED_VERSIONS, true)) {
            throw TargetPhpSettingsException::create(
                TargetPhpSettingsException::TARGET_PHP_VERSION_INVALID
            );
        }

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
