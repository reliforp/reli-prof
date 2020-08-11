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
use Symfony\Component\Console\Input\InputInterface;

final class DaemonSettingsFromConsoleInput
{
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
