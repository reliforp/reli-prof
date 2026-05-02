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
 * stream (tcp/udp/unix/udg). Reli only declares the leading php_socket_t
 * field; the accounted size reflects what was deref'd, not the full heap
 * allocation (the trailing sockaddr_storage / timeval / ownership-enum
 * fields vary by libc and PHP version).
 */
final class PhpNetstreamDataMemoryLocation extends MemoryLocation
{
}
