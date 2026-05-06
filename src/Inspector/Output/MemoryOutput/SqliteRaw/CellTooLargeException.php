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
 * Thrown when {@see SharedMmapTableWriter::workerWriteTable} encounters
 * a cell whose payload exceeds the inline payload limit
 * (~`page_size - 35`). Overflow chains aren't yet implemented; the
 * orchestrator catches this and falls back to the existing
 * parallel-shard ATTACH/MERGE path which tolerates large cells via
 * normal SQLite INSERTs.
 */
final class CellTooLargeException extends \RuntimeException
{
}
