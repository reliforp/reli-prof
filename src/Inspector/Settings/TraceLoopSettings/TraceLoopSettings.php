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

namespace Reli\Inspector\Settings\TraceLoopSettings;

final class TraceLoopSettings
{
    public const SLEEP_NANO_SECONDS_DEFAULT = 1000 * 1000 * 10;
    public const CANCEL_KEY_DEFAULT = 'q';
    public const MAX_RETRY_DEFAULT = 10;

    public function __construct(
        public int $sleep_nano_seconds,
        public string $cancel_key,
        public int $max_retries,
        public bool $stop_process,
    ) {
    }
}
