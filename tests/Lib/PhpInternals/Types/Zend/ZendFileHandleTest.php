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

namespace Reli\Lib\PhpInternals\Types\Zend;

use PHPUnit\Framework\TestCase;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;

class ZendFileHandleTest extends TestCase
{
    /** @return array{ZendFileHandle, ZendTypeReader} */
    private function makeFileHandle(string $version, int $buf, int $len, ?int $primary): array
    {
        $reader = new ZendTypeReader($version);
        $size = $reader->sizeOf('zend_file_handle');
        [$buf_off] = $reader->getOffsetAndSizeOfMember('zend_file_handle', 'buf');
        [$len_off] = $reader->getOffsetAndSizeOfMember('zend_file_handle', 'len');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");

        foreach (str_split(pack('P', $buf)) as $i => $byte) {
            $raw[$buf_off + $i] = \ord($byte);
        }
        foreach (str_split(pack('P', $len)) as $i => $byte) {
            $raw[$len_off + $i] = \ord($byte);
        }
        if ($primary !== null) {
            [$ps_off] = $reader->getOffsetAndSizeOfMember('zend_file_handle', 'primary_script');
            $raw[$ps_off] = $primary;
        }

        $casted = $reader->readAs('zend_file_handle', $raw);
        $handle = ZendFileHandle::fromCastedCData(
            $casted,
            new Pointer(ZendFileHandle::class, 0x1000, $size),
        );
        return [$handle, $reader];
    }

    public function testReadsBufAndLen(): void
    {
        // caller keeps $reader alive (the CData views its FFI scope).
        [$handle, $reader] = $this->makeFileHandle(ZendTypeReader::V82, 0x7f0000123000, 4096, 1);
        $this->assertSame(0x7f0000123000, $handle->buf);
        $this->assertSame(4096, $handle->len);
        $this->assertSame(1, $handle->primary_script);
        unset($reader);
    }

    public function testPrimaryScriptFlagDistinguishesIncludedFiles(): void
    {
        [$handle, $reader] = $this->makeFileHandle(ZendTypeReader::V82, 0x7f0000124000, 128, 0);
        $this->assertSame(0, $handle->primary_script);
        unset($reader);
    }

    public function testPrimaryScriptIsMinusOneWhenFieldAbsentPre81(): void
    {
        // PHP 8.0's zend_file_handle has no primary_script member (it carried
        // free_filename instead); the view must report -1 rather than throw.
        [$handle, $reader] = $this->makeFileHandle(ZendTypeReader::V80, 0x7f0000125000, 256, null);
        $this->assertSame(0x7f0000125000, $handle->buf);
        $this->assertSame(256, $handle->len);
        $this->assertSame(-1, $handle->primary_script);
        unset($reader);
    }
}
