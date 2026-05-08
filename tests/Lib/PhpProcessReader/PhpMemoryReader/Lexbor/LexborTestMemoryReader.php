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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Lexbor;

use FFI;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

/**
 * Test helper that lets the scanner hit specific addresses and returns
 * preloaded bytes. Falls back to zero-filled buffers for un-preloaded
 * reads (which the scanner treats as "no match" and skips).
 *
 * Slim alternative to the global `MockMemoryReader` because the scanner
 * issues sub-reads (32 / 40 / 24 bytes) at offsets that may not match a
 * preloaded `address:size` key exactly. This helper resolves those
 * sub-reads through a `[base, base + len)` range lookup instead.
 */
final class LexborTestMemoryReader implements MemoryReaderInterface
{
    /** @var array<int, string> */
    private array $regions = [];

    public function preload(int $address, string $bytes): void
    {
        $this->regions[$address] = $bytes;
    }

    #[\Override]
    public function read(int $pid, int $remote_address, int $size): FFI\CData
    {
        $bytes = $this->regions[$remote_address] ?? null;
        if ($bytes !== null) {
            $bytes = substr($bytes, 0, $size);
            if (strlen($bytes) < $size) {
                $bytes .= str_repeat("\0", $size - strlen($bytes));
            }
        } else {
            // Try to find a region that contains this address.
            $bytes = null;
            foreach ($this->regions as $base => $region_bytes) {
                $end = $base + strlen($region_bytes);
                if ($remote_address >= $base && $remote_address + $size <= $end) {
                    $bytes = substr($region_bytes, $remote_address - $base, $size);
                    break;
                }
            }
            if ($bytes === null) {
                $bytes = str_repeat("\0", $size);
            }
        }
        $buf = FFIHelper::new("char[$size]");
        FFI::memcpy($buf, $bytes, $size);
        /** @var FFI\CArray<int> */
        return $buf;
    }
}
