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

namespace Reli\Inspector\Settings\DaemonSettings;

final class DaemonSettings
{
    /**
     * @param non-empty-string $target_regex
     * @param non-empty-string|null $target_thread_regex optional per-thread
     *     filter, matched against /proc/<tid>/comm of each TID under a
     *     matching PID. Intended for ZTS targets where only named worker
     *     threads run the PHP VM (e.g. FrankenPHP names its workers
     *     `php-<hex>` via prctl(PR_SET_NAME)); setting
     *     `^php-[0-9a-f]+$` then skips the Caddy/Go runtime threads.
     */
    public function __construct(
        public string $target_regex,
        public int $threads,
        public ?string $target_thread_regex = null,
    ) {
    }
}
