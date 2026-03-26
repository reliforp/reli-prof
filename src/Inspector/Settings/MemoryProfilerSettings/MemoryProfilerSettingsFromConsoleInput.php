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

namespace Reli\Inspector\Settings\MemoryProfilerSettings;

use PhpCast\Cast;
use PhpCast\NullableCast;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class MemoryProfilerSettingsFromConsoleInput
{
    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command->addOption(
            'stop-process',
            null,
            InputOption::VALUE_NEGATABLE,
            'stop the process while inspecting (default: on)',
            true,
        );
        $command->addOption(
            'pretty-print',
            null,
            InputOption::VALUE_NEGATABLE,
            'pretty print the result (default: off)',
            false,
        );
        $command->addOption(
            'output-format',
            'f',
            InputOption::VALUE_REQUIRED,
            'output format (json, sqlite3, mysql, postgresql)',
            'json',
        );
        $command->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'output file path (required for sqlite3 format)',
        );
        $command->addOption(
            'db-host',
            null,
            InputOption::VALUE_REQUIRED,
            'database host (for mysql/postgresql)',
            '127.0.0.1',
        );
        $command->addOption(
            'db-port',
            null,
            InputOption::VALUE_REQUIRED,
            'database port (for mysql/postgresql)',
        );
        $command->addOption(
            'db-name',
            null,
            InputOption::VALUE_REQUIRED,
            'database name (for mysql/postgresql)',
        );
        $command->addOption(
            'db-user',
            null,
            InputOption::VALUE_REQUIRED,
            'database user (for mysql/postgresql)',
        );
        $command->addOption(
            'db-password',
            null,
            InputOption::VALUE_REQUIRED,
            'database password (for mysql/postgresql)',
        );
        $command->addOption(
            'memory-limit-error-file',
            null,
            InputOption::VALUE_REQUIRED,
            'file path where memory_limit is exceeded'
        );
        $command->addOption(
            'memory-limit-error-line',
            null,
            InputOption::VALUE_REQUIRED,
            'line number where memory_limit is exceeded'
        );
        $command->addOption(
            'memory-limit-error-max-depth',
            null,
            InputOption::VALUE_OPTIONAL,
            'max attempts to trace back the VM stack on memory_limit error',
            512,
        );
    }

    public function createSettings(InputInterface $input): MemoryProfilerSettings
    {
        $stop_process = Cast::toBool($input->getOption('stop-process'));
        $pretty_print = Cast::toBool($input->getOption('pretty-print'));
        $memory_exhaustion_error_details = null;
        if (
            $input->getOption('memory-limit-error-file') !== null
            and $input->getOption('memory-limit-error-line') !== null
        ) {
            $memory_limit_error_max_depth = filter_var(
                $input->getOption('memory-limit-error-max-depth'),
                FILTER_VALIDATE_INT
            );
            if (
                $memory_limit_error_max_depth === false
                or $memory_limit_error_max_depth < 1
            ) {
                throw MemoryProfilerSettingsException::create(
                    MemoryProfilerSettingsException::MEMORY_LIMIT_ERROR_MAX_DEPTH_IS_NOT_POSITIVE_INTEGER
                );
            }
            $line = filter_var($input->getOption('memory-limit-error-line'), FILTER_VALIDATE_INT);
            if ($line === false) {
                throw MemoryProfilerSettingsException::create(
                    MemoryProfilerSettingsException::MEMORY_LIMIT_ERROR_LINE_IS_NOT_INTEGER
                );
            }
            $memory_exhaustion_error_details = new MemoryLimitErrorDetails(
                Cast::toString($input->getOption('memory-limit-error-file')),
                $line,
                $memory_limit_error_max_depth,
            );
        }
        $output_format = Cast::toString($input->getOption('output-format'));
        $output_path = NullableCast::toString($input->getOption('output'));
        $db_host = Cast::toString($input->getOption('db-host'));
        $db_port = NullableCast::toInt($input->getOption('db-port'));
        $db_name = NullableCast::toString($input->getOption('db-name'));
        $db_user = NullableCast::toString($input->getOption('db-user'));
        $db_password = NullableCast::toString($input->getOption('db-password'));

        return new MemoryProfilerSettings(
            $stop_process,
            $pretty_print,
            $memory_exhaustion_error_details,
            $output_format,
            $output_path,
            $db_host,
            $db_port,
            $db_name,
            $db_user,
            $db_password,
        );
    }
}
