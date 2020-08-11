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

namespace PhpProfiler\Inspector\Settings\TraceLoopSettings;

final class TraceLoopSettings
{
    public const SLEEP_NANO_SECONDS_DEFAULT = 1000 * 1000 * 10;
    public const CANCEL_KEY_DEFAULT = 'q';
    public const MAX_RETRY_DEFAULT = 10;

    public int $sleep_nano_seconds;
    public string $cancel_key;
    public int $max_retries;

    /**
     * TraceLoopSettings constructor.
     * @param int $sleep_nano_seconds
     * @param string $cancel_key
     * @param int $max_retries
     */
    public function __construct(int $sleep_nano_seconds, string $cancel_key, int $max_retries)
    {
        $this->sleep_nano_seconds = $sleep_nano_seconds;
        $this->cancel_key = $cancel_key;
        $this->max_retries = $max_retries;
    }
}
