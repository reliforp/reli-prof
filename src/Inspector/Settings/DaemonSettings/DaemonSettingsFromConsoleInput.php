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

namespace PhpProfiler\Inspector\Settings\DaemonSettings;

use PhpProfiler\Inspector\Settings\InspectorSettingsException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class DaemonSettingsFromConsoleInput
{
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'threads',
                'T',
                InputOption::VALUE_OPTIONAL,
                'number of workers (default: 8)'
            )
            ->addOption(
                'target-regex',
                'P',
                InputOption::VALUE_OPTIONAL,
                'regex to find the php binary loaded in the target process'
            )
        ;
    }

    /**
     * @param InputInterface $input
     * @return DaemonSettings
     * @throws InspectorSettingsException
     */
    public function fromConsoleInput(InputInterface $input): DaemonSettings
    {
        $threads = $input->getOption('threads');
        if (is_null($threads)) {
            $threads = 8;
        }
        $threads = filter_var($threads, FILTER_VALIDATE_INT);
        if ($threads === false) {
            throw DaemonSettingsException::create(DaemonSettingsException::THREADS_IS_NOT_INTEGER);
        }

        $target_regex = $input->getOption('target-regex') ?? DaemonSettings::TARGET_REGEX_DEFAULT;
        if (!is_string($target_regex)) {
            throw DaemonSettingsException::create(
                DaemonSettingsException::TARGET_REGEX_IS_NOT_STRING
            );
        }

        return new DaemonSettings('{' . $target_regex . '}', $threads);
    }
}
