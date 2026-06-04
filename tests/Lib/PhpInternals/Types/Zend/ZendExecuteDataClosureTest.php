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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;

class ZendExecuteDataClosureTest extends TestCase
{
    /**
     * Build a zend_execute_data whose This.u1.type_info holds $call_info.
     *
     * The caller owns $reader: the casted CData is a view into its FFI scope,
     * so the reader must outlive every field access on the returned object.
     */
    private function makeExecuteData(ZendTypeReader $reader, string $version, int $call_info): ZendExecuteData
    {
        $size = $reader->sizeOf('zend_execute_data');
        [$this_offset] = $reader->getOffsetAndSizeOfMember('zend_execute_data', 'This');
        // This is a zval; its u1.type_info is the 4 bytes after the 8-byte value.
        $type_info_offset = $this_offset + 8;
        $raw = \FFI::cdef()->new("unsigned char[{$size}]");
        $packed = pack('V', $call_info);
        for ($b = 0; $b < 4; $b++) {
            $raw[$type_info_offset + $b] = \ord($packed[$b]);
        }
        $casted = $reader->readAs('zend_execute_data', $raw);
        return ZendExecuteData::fromCastedCDataWithResolver(
            $casted,
            new Pointer(ZendExecuteData::class, 0x1000, $size),
            new VersionedPointedTypeResolver($version),
        );
    }

    public function testIsClosureCallWhenFlagSet(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V82);
        // flag set alongside unrelated call-info bits — still a closure call.
        $execute_data = $this->makeExecuteData($reader, ZendTypeReader::V82, (1 << 22) | (1 << 20));
        $this->assertTrue($execute_data->isClosureCall($reader));
    }

    public function testNotClosureCallWhenFlagClear(): void
    {
        $reader = new ZendTypeReader(ZendTypeReader::V82);
        // neighbouring bits set, but not ZEND_CALL_CLOSURE.
        $execute_data = $this->makeExecuteData($reader, ZendTypeReader::V82, (1 << 21) | (1 << 23));
        $this->assertFalse($execute_data->isClosureCall($reader));
    }

    /**
     * The effective ZEND_CALL_CLOSURE bit moved across PHP versions
     * (ZEND_CALL_INFO_SHIFT 24 on 7.0, the pre-7.4 bit numbering on 7.1-7.3,
     * the modern numbering on 7.4+). isClosureCall() must honour the
     * version-specific position, not a single literal.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function closureBitProvider(): iterable
    {
        yield '7.0 (shift 24, bit 5 -> 29)' => [ZendTypeReader::V70, 1 << 29];
        yield '7.2 (shift 16, bit 5 -> 21)' => [ZendTypeReader::V72, 1 << 21];
        yield '7.3 (shift 16, bit 5 -> 21)' => [ZendTypeReader::V73, 1 << 21];
        yield '7.4 (renumbered, bit 6 -> 22)' => [ZendTypeReader::V74, 1 << 22];
        yield '8.2 (bit 6 -> 22)' => [ZendTypeReader::V82, 1 << 22];
    }

    #[DataProvider('closureBitProvider')]
    public function testClosureBitPositionIsVersionAware(string $version, int $closure_bit): void
    {
        $reader = new ZendTypeReader($version);

        $closure_frame = $this->makeExecuteData($reader, $version, $closure_bit);
        $this->assertTrue(
            $closure_frame->isClosureCall($reader),
            "the version-specific closure bit must register on {$version}",
        );

        // The closure bit of a *different* version must not be mistaken for a
        // closure call under this version's layout.
        $non_closure_frame = $this->makeExecuteData($reader, $version, $closure_bit << 1);
        $this->assertFalse(
            $non_closure_frame->isClosureCall($reader),
            "a neighbouring bit must not register as a closure call on {$version}",
        );
    }
}
