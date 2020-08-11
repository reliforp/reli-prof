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

final class DaemonSettings
{
    public const TARGET_REGEX_DEFAULT = '^php-fpm';

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
}
