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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

use Reli\Lib\Process\MemoryLocation;

interface ReferenceContext
{
    public function getName(): string;

    public function add(string $link_name, self $reference_context): void;

    /** @return iterable<string, self> */
    public function getLinks(): iterable;

    /** @return iterable<array-key, MemoryLocation> */
    public function getLocations(): iterable;

    public function getContexts(): iterable;
}
