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

use FFI;
use FFI\CData;
use Reli\BaseTestCase;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

class LinkMapLoaderTest extends BaseTestCase
{
    public function testReadsCStringWithoutCrossingPageBoundaries(): void
    {
        // Place a 40-byte link map struct at 0x1000 and a name string at
        // 0x1FF0 (16 bytes before the next page boundary). The name itself
        // is "/lib/x86_64-linux-gnu/libfoo.so.6\0" (33 bytes including NUL),
        // so it spans the 0x2000 boundary. The fake reader refuses any
        // read that crosses a 4096-byte boundary -- exactly mimicking
        // process_vm_readv's EFAULT behaviour at unmapped page edges.
        $page_size = 4096;
        $name = '/lib/x86_64-linux-gnu/libfoo.so.6';
        $name_addr = 0x1FF0;
        $struct_addr = 0x1000;

        $memory = [];
        // l_addr, l_name, l_ld, l_next, l_prev (5 * 8 bytes = 40)
        $memory[$struct_addr] = pack('PPPPP', 0x10000, $name_addr, 0, 0, 0);
        // The name plus terminator at $name_addr; pad with NULs before
        // it so the test reader can serve any aligned read inside the
        // page that contains the name.
        $name_with_nul = $name . "\0";
        // Fill page 0x1000 (covers struct + name) with structured bytes:
        // 0x1000..0x1027  link-map struct
        // 0x1FF0..0x2010  name
        $page_1000 = str_repeat("\0", $page_size);
        $page_1000 = substr_replace($page_1000, $memory[$struct_addr], 0, 40);
        $name_in_page_1000 = substr($name_with_nul, 0, $page_size - 0xFF0);
        $page_1000 = substr_replace(
            $page_1000,
            $name_in_page_1000,
            0xFF0,
            strlen($name_in_page_1000),
        );
        $page_2000 = str_repeat("\0", $page_size);
        $name_remaining = substr($name_with_nul, $page_size - 0xFF0);
        $page_2000 = substr_replace($page_2000, $name_remaining, 0, strlen($name_remaining));

        $pages = [
            0x1000 => $page_1000,
            0x2000 => $page_2000,
        ];

        $reader = $this->makePageBoundedReader($pages, $page_size);

        $loader = new LinkMapLoader($reader, new LittleEndianReader());
        $link_map = $loader->loadFromAddress(123, $struct_addr);

        $this->assertSame($name, $link_map->l_name);
    }

    public function testReadsCStringThatStartsAtPageBoundary(): void
    {
        // Edge case: the name pointer lands exactly on a page boundary.
        // The very first chunk would otherwise be sized as a full page,
        // so make sure we still respect the page-boundary clip even when
        // remaining_in_page == page_size.
        $page_size = 4096;
        $name = 'libphp.so';
        $name_addr = 0x3000;
        $struct_addr = 0x1000;

        $page_1000 = str_repeat("\0", $page_size);
        $page_1000 = substr_replace(
            $page_1000,
            pack('PPPPP', 0, $name_addr, 0, 0, 0),
            0,
            40,
        );
        $page_3000 = str_repeat("\0", $page_size);
        $page_3000 = substr_replace($page_3000, $name . "\0", 0, strlen($name) + 1);

        $pages = [
            0x1000 => $page_1000,
            0x3000 => $page_3000,
            // 0x2000 deliberately unmapped — readCString must not stray
            // into it via a coincidentally-overrun read.
        ];

        $reader = $this->makePageBoundedReader($pages, $page_size);

        $loader = new LinkMapLoader($reader, new LittleEndianReader());
        $link_map = $loader->loadFromAddress(123, $struct_addr);

        $this->assertSame($name, $link_map->l_name);
    }

    /** @param array<int, string> $pages */
    private function makePageBoundedReader(array $pages, int $page_size): MemoryReaderInterface
    {
        return new class ($pages, $page_size) implements MemoryReaderInterface {
            private FFI $ffi;

            /** @param array<int, string> $pages */
            public function __construct(
                private array $pages,
                private int $page_size,
            ) {
                $this->ffi = FFI::cdef('');
            }

            #[\Override]
            public function read(int $pid, int $remote_address, int $size): CData
            {
                $page_base = $remote_address & ~($this->page_size - 1);
                $offset_in_page = $remote_address & ($this->page_size - 1);
                if ($offset_in_page + $size > $this->page_size) {
                    throw new MemoryReaderException(
                        sprintf(
                            'fake EFAULT: read crosses page boundary (addr=0x%x size=%d)',
                            $remote_address,
                            $size,
                        ),
                    );
                }
                if (!isset($this->pages[$page_base])) {
                    throw new MemoryReaderException(
                        sprintf('fake EFAULT: unmapped page 0x%x', $page_base),
                    );
                }
                $bytes = substr($this->pages[$page_base], $offset_in_page, $size);
                $buffer = $this->ffi->new("unsigned char[{$size}]");
                if ($buffer === null) {
                    throw new MemoryReaderException('cannot allocate buffer');
                }
                FFI::memcpy($buffer, $bytes, $size);
                /** @var \FFI\CArray<int> */
                return $buffer;
            }
        };
    }
}
