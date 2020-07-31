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

namespace PhpProfiler\Inspector\Settings;

use PhpProfiler\Inspector\Settings\InspectorSettingsException;
use Symfony\Component\Console\Input\InputInterface;

final class DaemonSettings
{
    private const TARGET_REGEX_DEFAULT = '^php-fpm';

    public string $target_regex;
    public int $threads;

    /**
     * DaemonSettings constructor.
     * @param string $target_regex
     * @param int $threads
     */
    public function __construct(string $target_regex, int $threads)
    {
        $this->target_regex = $target_regex;
        $this->threads = $threads;
    }

    /**
     * @param InputInterface $input
     * @return self
     * @throws InspectorSettingsException
     */
    public static function fromConsoleInput(InputInterface $input): self
    {
        $threads = $input->getOption('threads');
        if (is_null($threads)) {
            $threads = 8;
        }
        $threads = filter_var($threads, FILTER_VALIDATE_INT);
        if ($threads === false) {
            throw DaemonInspectorSettingsException::create(DaemonInspectorSettingsException::THREADS_IS_NOT_INTEGER);
        }

        $target_regex = $input->getOption('target-regex') ?? self::TARGET_REGEX_DEFAULT;
        if (!is_string($target_regex)) {
            throw DaemonInspectorSettingsException::create(
                DaemonInspectorSettingsException::TARGET_REGEX_IS_NOT_STRING
            );
        }

        return new self('{' . $target_regex . '}', $threads);
    }
}
