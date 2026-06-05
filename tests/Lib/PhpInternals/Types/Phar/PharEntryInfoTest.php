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

namespace Reli\Lib\PhpInternals\Types\Phar;

use PHPUnit\Framework\TestCase;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;

class PharEntryInfoTest extends TestCase
{
    /** Poke $size little-endian bytes of $value at $offset. */
    private function poke(\FFI\CData $raw, int $offset, int $size, int $value): void
    {
        $packed = pack('P', $value); // 8 LE bytes; take the low $size
        for ($b = 0; $b < $size; $b++) {
            $raw[$offset + $b] = \ord($packed[$b]);
        }
    }

    /** @return array{PharEntryInfo, ZendTypeReader} caller keeps reader alive */
    private function make(string $version, int $filename, int $filename_len, int $metadata): array
    {
        $reader = new ZendTypeReader($version);
        $size = $reader->sizeOf('phar_entry_info');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");

        [$fn_off, $fn_size] = $reader->getOffsetAndSizeOfMember('phar_entry_info', 'filename');
        [$len_off, $len_size] = $reader->getOffsetAndSizeOfMember('phar_entry_info', 'filename_len');
        $this->poke($raw, $fn_off, $fn_size, $filename);
        $this->poke($raw, $len_off, $len_size, $filename_len);
        // metadata_str only exists in the 8.0+ layout; skip the poke when the
        // member lookup fails (pre-8.0), mirroring PharEntryInfo::readMetadataStr.
        try {
            [$md_off, $md_size] = $reader->getOffsetAndSizeOfMember('phar_entry_info', 'metadata_str');
            $this->poke($raw, $md_off, $md_size, $metadata);
        } catch (\Throwable) {
            // pre-8.0: no metadata_str field
        }

        $casted = $reader->readAs('phar_entry_info', $raw);
        $entry = PharEntryInfo::fromCastedCData(
            $casted,
            new Pointer(PharEntryInfo::class, 0x1000, $size),
        );
        return [$entry, $reader];
    }

    public function testReadsFilenameAndMetadataOn82(): void
    {
        [$entry, $reader] = $this->make(ZendTypeReader::V82, 0x7f0000aa0000, 17, 0x7f0000bb0000);
        $this->assertSame(0x7f0000aa0000, $entry->filename_address);
        $this->assertSame(17, $entry->filename_len);
        $this->assertSame(0x7f0000bb0000, $entry->metadata_str_address);
        unset($reader);
    }

    public function testMetadataStrIsZeroWhenFieldAbsentPre80(): void
    {
        // The pre-8.0 phar_entry_info layout has metadata_str_s/_a, not the
        // single metadata_str pointer, so the read must fall back to 0.
        [$entry, $reader] = $this->make(ZendTypeReader::V74, 0x7f0000aa0000, 9, 0);
        $this->assertSame(0x7f0000aa0000, $entry->filename_address);
        $this->assertSame(9, $entry->filename_len);
        $this->assertSame(0, $entry->metadata_str_address);
        unset($reader);
    }
}
