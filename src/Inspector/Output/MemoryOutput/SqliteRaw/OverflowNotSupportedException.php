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

namespace Reli\Inspector\Output\MemoryOutput\SqliteRaw;

/**
 * Thrown when the format-direct merger encounters a leaf cell whose
 * payload exceeds the inline threshold (i.e., would have an overflow
 * page chain). The MVP merger declines this case so the caller can
 * fall back to the SQL `INSERT INTO main FROM s` path for the
 * affected table.
 */
final class OverflowNotSupportedException extends \RuntimeException
{
}
