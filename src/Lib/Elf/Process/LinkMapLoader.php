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

namespace Reli\Lib\Elf\Process;

use Reli\Lib\ByteStream\CDataByteReader;
use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class LinkMapLoader
{
    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private IntegerByteSequenceReader $integer_reader,
    ) {
    }

    public function loadFromAddress(int $pid, int $address): LinkMap
    {
        $bytes = new CDataByteReader($this->memory_reader->read($pid, $address, $this->getSize()));
        $l_addr = $this->integer_reader->read64($bytes, 0);
        $l_name_address = $this->integer_reader->read64($bytes, 8);
        $l_ld = $this->integer_reader->read64($bytes, 16);
        $l_next_address = $this->integer_reader->read64($bytes, 24);
        $l_prev_address = $this->integer_reader->read64($bytes, 32);

        return new LinkMap(
            $address,
            $l_addr->toInt(),
            $this->readCString($pid, $l_name_address->toInt()),
            $l_ld->toInt(),
            $l_next_address->toInt(),
            $l_prev_address->toInt()
        );
    }

    public function searchByName(string $name, int $pid, int $root_address): ?LinkMap
    {
        $address = $root_address;
        do {
            $link_map = $this->loadFromAddress($pid, $address);
            if ($link_map->l_name === $name) {
                return $link_map;
            }
            $address = $link_map->l_next_address;
        } while ($address !== 0);
        return null;
    }

    private function getSize(): int
    {
        return 8 // l_addr
            + 8 // l_name
            + 8 // l_ld
            + 8 // l_next
            + 8 // l_prev
        ;
    }

    private function readCString(int $pid, int $address): string
    {
        // Cold-attach hot path: link-map traversal during cold-attach has
        // to read every loaded library's path (DT_NEEDED-style), and naive
        // byte-by-byte reads cost one process_vm_readv syscall per
        // character. Reading in 256-byte chunks turns each typical 30-40
        // character path into a single syscall.
        $chunk_size = 256;
        $str = '';
        while (true) {
            $chunk = $this->memory_reader->read($pid, $address, $chunk_size);
            $found_terminator = false;
            for ($i = 0; $i < $chunk_size; $i++) {
                /** @var int $c */
                $c = $chunk[$i];
                if ($c === 0) {
                    $found_terminator = true;
                    break;
                }
                $str .= chr($c);
            }
            if ($found_terminator) {
                break;
            }
            $address += $chunk_size;
        }
        return $str;
    }
}
