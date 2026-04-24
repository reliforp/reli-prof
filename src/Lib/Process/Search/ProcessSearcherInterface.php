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

namespace Reli\Lib\Process\Search;

interface ProcessSearcherInterface
{
    /**
     * @param non-empty-string $regex
     * @param non-empty-string|null $thread_name_regex optional per-thread
     *     filter matched against /proc/<tid>/comm. TIDs whose comm does not
     *     match are skipped; useful for targets like FrankenPHP where the
     *     PHP VM only lives on pthread-named worker threads (`php-<hex>`)
     *     and most other TIDs are Go runtime threads without PHP state.
     * @return int[]
     */
    public function searchByRegex(string $regex, ?string $thread_name_regex = null): array;
}
