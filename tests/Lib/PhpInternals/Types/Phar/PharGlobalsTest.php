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

class PharGlobalsTest extends TestCase
{
    private function poke(\FFI\CData $raw, int $offset, int $size, int $value): void
    {
        $packed = pack('P', $value);
        for ($b = 0; $b < $size; $b++) {
            $raw[$offset + $b] = \ord($packed[$b]);
        }
    }

    public function testReadsScalarPointerAndLengthFields(): void
    {
        // caller owns $reader: the casted CData views its FFI scope.
        $version = ZendTypeReader::V82;
        $reader = new ZendTypeReader($version);
        $size = $reader->sizeOf('phar_globals_truncated');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");

        $fields = [
            'cwd' => 0x7f0000010000,
            'cwd_len' => 12,
            'last_phar_name' => 0x7f0000020000,
            'last_phar_name_len' => 30,
            'last_alias' => 0x7f0000030000,
            'last_alias_len' => 5,
            'cache_list' => 0x7f0000040000,
        ];
        foreach ($fields as $member => $value) {
            [$off, $msize] = $reader->getOffsetAndSizeOfMember('phar_globals_truncated', $member);
            $this->poke($raw, $off, $msize, $value);
        }

        $casted = $reader->readAs('phar_globals_truncated', $raw);
        $globals = PharGlobals::fromCastedCDataWithResolver(
            $casted,
            new Pointer(PharGlobals::class, 0x1000, $size),
            new VersionedPointedTypeResolver($version),
        );

        $this->assertSame(0x7f0000010000, $globals->cwd_address);
        $this->assertSame(12, $globals->cwd_len);
        $this->assertSame(0x7f0000020000, $globals->last_phar_name_address);
        $this->assertSame(30, $globals->last_phar_name_len);
        $this->assertSame(0x7f0000030000, $globals->last_alias_address);
        $this->assertSame(5, $globals->last_alias_len);
        $this->assertSame(0x7f0000040000, $globals->cache_list_address);
    }

    public function testZeroedGlobalsReportZeroAddresses(): void
    {
        $version = ZendTypeReader::V82;
        $reader = new ZendTypeReader($version);
        $size = $reader->sizeOf('phar_globals_truncated');
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");

        $casted = $reader->readAs('phar_globals_truncated', $raw);
        $globals = PharGlobals::fromCastedCDataWithResolver(
            $casted,
            new Pointer(PharGlobals::class, 0x1000, $size),
            new VersionedPointedTypeResolver($version),
        );

        $this->assertSame(0, $globals->cwd_address);
        $this->assertSame(0, $globals->last_phar_name_address);
        $this->assertSame(0, $globals->cache_list_address);
    }
}
