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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation;

use Reli\Lib\Process\MemoryLocation;

/**
 * The php_stdio_stream_data abstract block backing an STDIO stream
 * (pointed to by php_stream->abstract for streams whose ops->label is
 * "STDIO"). Reli only declares the leading 32 bytes for FFI deref (up
 * to temp_name), but the registered range covers the full allocation
 * as found on a typical Linux x86_64 build (192 bytes: leading 32 +
 * HAVE_MMAP last_mapped_addr/len + struct stat). Alpine/musl builds
 * with HAVE_FLUSHIO defined are slightly larger (200 B); we keep the
 * conservative 192 to avoid overclaiming past the real allocation.
 */
final class PhpStdioStreamDataMemoryLocation extends MemoryLocation
{
}
