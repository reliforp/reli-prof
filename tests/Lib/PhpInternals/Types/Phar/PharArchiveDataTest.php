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
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;

class PharArchiveDataTest extends TestCase
{
    private function poke(\FFI\CData $raw, int $offset, int $size, int $value): void
    {
        $packed = pack('P', $value);
        for ($b = 0; $b < $size; $b++) {
            $raw[$offset + $b] = \ord($packed[$b]);
        }
    }

    public function testReadsStreamAndBufferPointerFields(): void
    {
        // caller owns $reader: the casted CData views its FFI scope.
        $version = ZendTypeReader::V82;
        $reader = new ZendTypeReader($version);
        $size = $reader->sizeOf('phar_archive_data_truncated');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");

        $fields = [
            'fp' => 0x7f0000100000,
            'ufp' => 0x7f0000200000,
            'fname' => 0x7f0000300000,
            'fname_len' => 21,
            'ext' => 0x7f0000400000,
            'ext_len' => 5,
            'alias' => 0x7f0000500000,
            'alias_len' => 7,
            'signature' => 0x7f0000600000,
            'sig_len' => 20,
        ];
        foreach ($fields as $member => $value) {
            [$off, $msize] = $reader->getOffsetAndSizeOfMember('phar_archive_data_truncated', $member);
            $this->poke($raw, $off, $msize, $value);
        }

        $casted = $reader->readAs('phar_archive_data_truncated', $raw);
        $archive = PharArchiveData::fromCastedCDataWithResolver(
            $casted,
            new Pointer(PharArchiveData::class, 0x1000, $size),
            new VersionedPointedTypeResolver($version),
        );

        $this->assertSame(0x7f0000100000, $archive->fp_address);
        $this->assertSame(0x7f0000200000, $archive->ufp_address);
        $this->assertSame(0x7f0000300000, $archive->fname->address);
        $this->assertSame(21, $archive->fname_len);
        $this->assertSame(0x7f0000400000, $archive->ext_address);
        $this->assertSame(5, $archive->ext_len);
        $this->assertSame(0x7f0000500000, $archive->alias_address);
        $this->assertSame(7, $archive->alias_len);
        $this->assertSame(0x7f0000600000, $archive->signature_address);
        $this->assertSame(20, $archive->sig_len);
    }

    public function testUnsignedArchiveReportsZeroSignature(): void
    {
        $version = ZendTypeReader::V82;
        $reader = new ZendTypeReader($version);
        $size = $reader->sizeOf('phar_archive_data_truncated');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");

        $casted = $reader->readAs('phar_archive_data_truncated', $raw);
        $archive = PharArchiveData::fromCastedCDataWithResolver(
            $casted,
            new Pointer(PharArchiveData::class, 0x1000, $size),
            new VersionedPointedTypeResolver($version),
        );

        $this->assertSame(0, $archive->signature_address);
        $this->assertSame(0, $archive->fp_address);
    }
}
