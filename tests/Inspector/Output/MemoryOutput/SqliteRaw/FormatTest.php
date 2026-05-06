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

namespace Reli\Inspector\Output\MemoryOutput\SqliteRaw;

use Reli\BaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The varint codec is the single most failure-prone part of the
 * SQLite file format — a one-bit error silently corrupts every cell
 * payload that goes through it. This test exercises the boundaries
 * where the byte-length transitions, plus a few values that are
 * representative of what we actually emit (rowids, payload sizes,
 * type codes).
 *
 * Reference values verified against the SQLite documentation
 * (https://www.sqlite.org/fileformat.html#varint).
 */
class FormatTest extends BaseTestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function varintCases(): iterable
    {
        yield 'zero' => [0, "\x00"];
        yield 'one' => [1, "\x01"];
        yield 'max one byte (127)' => [127, "\x7f"];
        yield 'min two byte (128)' => [128, "\x81\x00"];
        yield 'two byte 255' => [255, "\x81\x7f"];
        yield 'two byte 256' => [256, "\x82\x00"];
        yield 'max two byte (16383)' => [16383, "\xff\x7f"];
        yield 'min three byte (16384)' => [16384, "\x81\x80\x00"];
        yield '2^28' => [1 << 28, "\x81\x80\x80\x80\x00"];
        yield 'max u32 (2^32 - 1)' => [(1 << 32) - 1, "\x8f\xff\xff\xff\x7f"];
        yield 'min six byte (2^32)' => [1 << 32, "\x90\x80\x80\x80\x00"];
        yield 'max 8-byte form (2^56 - 1)' => [(1 << 56) - 1, "\xff\xff\xff\xff\xff\xff\xff\x7f"];
        yield 'min 9-byte form (2^56)' => [1 << 56, "\x80\xc0\x80\x80\x80\x80\x80\x80\x00"];
    }

    #[DataProvider('varintCases')]
    public function testVarintRoundTrip(int $value, string $expected_encoding): void
    {
        $encoded = Format::writeVarint($value);
        self::assertSame(
            $expected_encoding,
            $encoded,
            sprintf('encoding mismatch for %d: got %s', $value, bin2hex($encoded)),
        );

        // Decode at offset 0, padded with zeros so readVarint can read up
        // to 9 bytes regardless of the encoded length.
        [$decoded, $consumed] = Format::readVarint($encoded . str_repeat("\x00", 9), 0);
        self::assertSame($value, $decoded);
        self::assertSame(strlen($expected_encoding), $consumed);
    }

    public function testVarintAtNonZeroOffset(): void
    {
        $buf = "\x99\xaa" . Format::writeVarint(16384) . "\xbb\xcc";
        [$value, $consumed] = Format::readVarint($buf, 2);
        self::assertSame(16384, $value);
        self::assertSame(3, $consumed);
    }

    public function testNegativeVarintRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Format::writeVarint(-1);
    }

    public function testHeaderConstantsMatchSpec(): void
    {
        // Sanity check: the magic string and offsets that the doc
        // references must be the canonical ones.
        self::assertSame(16, strlen(Format::MAGIC));
        self::assertSame("SQLite format 3\0", Format::MAGIC);
        self::assertSame(100, Format::HEADER_SIZE);
        self::assertSame(16, Format::HDR_PAGE_SIZE);
        self::assertSame(28, Format::HDR_DB_PAGE_COUNT);
        self::assertSame(24, Format::HDR_CHANGE_COUNTER);
        // Page-type byte values are 0x02, 0x05, 0x0a, 0x0d.
        self::assertSame(0x02, Format::BTREE_INTERIOR_INDEX);
        self::assertSame(0x05, Format::BTREE_INTERIOR_TABLE);
        self::assertSame(0x0a, Format::BTREE_LEAF_INDEX);
        self::assertSame(0x0d, Format::BTREE_LEAF_TABLE);
    }
}
