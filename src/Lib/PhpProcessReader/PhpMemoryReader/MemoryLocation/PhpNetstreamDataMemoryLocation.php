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
 * The php_netstream_data_t abstract block backing a transport socket
 * stream (tcp/udp/unix/udg). Reli only declares the leading
 * php_socket_t field for FFI deref, but the registered range covers
 * the full allocation as found on a typical Linux x86_64 build
 * (168 bytes: socket/addrlen/sockaddr_storage/is_blocked/timeval/
 * timeout_event/ownership). sockaddr_storage and timeval are the same
 * size on glibc and musl on x86_64, so the value holds for both libcs.
 */
final class PhpNetstreamDataMemoryLocation extends MemoryLocation
{
}
