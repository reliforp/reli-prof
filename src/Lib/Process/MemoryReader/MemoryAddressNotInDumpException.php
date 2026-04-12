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

namespace Reli\Lib\Process\MemoryReader;

/**
 * Raised when a dump-backed MemoryReaderInterface is asked for an address
 * that is not present in the dump file (neither in the captured region
 * index nor in any file-backed fallback segment).
 *
 * This is semantically distinct from {@see MemoryReaderException}, which
 * covers genuine read failures against a live process (EFAULT, ESRCH,
 * etc.). A MemoryAddressNotInDumpException means "the dump we are
 * analysing did not capture these bytes" — typically because the
 * profiler ran in minimum mode and the caller just tried to follow a
 * pointer that only exists in the glibc [heap] or in an anon-writable
 * mmap region that was deliberately excluded.
 *
 * The iterative-DFS job queue in MemoryLocationsCollector already
 * catches \Throwable around each job, so raising this exception is
 * safe: the surrounding job is skipped and the collection continues
 * with the remaining queue. A dedicated type lets future code (report
 * aggregation, CI coverage gates, surrogate-node emission) distinguish
 * "pointer target not in dump" from "reader is broken".
 */
final class MemoryAddressNotInDumpException extends MemoryReaderException
{
}
