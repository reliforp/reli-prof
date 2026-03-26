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

namespace Reli\Lib\Dwarf;

use Reli\Lib\ByteStream\ByteReaderInterface;
use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;

final class DwarfPointerEncoding
{
    public const DW_EH_PE_ABSPTR = 0x00;
    public const DW_EH_PE_ULEB128 = 0x01;
    public const DW_EH_PE_UDATA2 = 0x02;
    public const DW_EH_PE_UDATA4 = 0x03;
    public const DW_EH_PE_UDATA8 = 0x04;
    public const DW_EH_PE_SLEB128 = 0x09;
    public const DW_EH_PE_SDATA2 = 0x0a;
    public const DW_EH_PE_SDATA4 = 0x0b;
    public const DW_EH_PE_SDATA8 = 0x0c;

    public const DW_EH_PE_PCREL = 0x10;
    public const DW_EH_PE_TEXTREL = 0x20;
    public const DW_EH_PE_DATAREL = 0x30;
    public const DW_EH_PE_FUNCREL = 0x40;
    public const DW_EH_PE_ALIGNED = 0x50;

    public const DW_EH_PE_INDIRECT = 0x80;
    public const DW_EH_PE_OMIT = 0xff;

    /**
     * @return array{int, int} [value, bytesConsumed]
     */
    public static function decode(
        ByteReaderInterface $data,
        int $offset,
        int $encoding,
        int $pcRelBase,
        IntegerByteSequenceReader $integer_reader,
    ): array {
        if ($encoding === self::DW_EH_PE_OMIT) {
            return [0, 0];
        }

        $format = $encoding & 0x0f;
        $application = $encoding & 0x70;

        [$raw_value, $bytes_consumed] = self::decodeValue($data, $offset, $format, $integer_reader);

        $value = match ($application) {
            self::DW_EH_PE_PCREL => $raw_value + $pcRelBase,
            0x00 => $raw_value, // DW_EH_PE_ABSPTR application
            default => $raw_value, // textrel, datarel, funcrel - need additional base
        };

        return [$value, $bytes_consumed];
    }

    /**
     * @return array{int, int} [value, bytesConsumed]
     */
    private static function decodeValue(
        ByteReaderInterface $data,
        int $offset,
        int $format,
        IntegerByteSequenceReader $integer_reader,
    ): array {
        return match ($format) {
            self::DW_EH_PE_ABSPTR => [
                $integer_reader->read64($data, $offset)->toInt(),
                8,
            ],
            self::DW_EH_PE_ULEB128 => Leb128::decodeUnsigned($data, $offset),
            self::DW_EH_PE_UDATA2 => [
                $integer_reader->read16($data, $offset),
                2,
            ],
            self::DW_EH_PE_UDATA4 => [
                $integer_reader->read32($data, $offset),
                4,
            ],
            self::DW_EH_PE_UDATA8 => [
                $integer_reader->read64($data, $offset)->toInt(),
                8,
            ],
            self::DW_EH_PE_SLEB128 => Leb128::decodeSigned($data, $offset),
            self::DW_EH_PE_SDATA2 => [
                self::signExtend16($integer_reader->read16($data, $offset)),
                2,
            ],
            self::DW_EH_PE_SDATA4 => [
                self::signExtend32($integer_reader->read32($data, $offset)),
                4,
            ],
            self::DW_EH_PE_SDATA8 => [
                $integer_reader->read64($data, $offset)->toInt(),
                8,
            ],
            default => throw new DwarfException("unsupported pointer encoding format: 0x" . dechex($format)),
        };
    }

    private static function signExtend16(int $value): int
    {
        if ($value & 0x8000) {
            return $value | ~0xffff;
        }
        return $value;
    }

    private static function signExtend32(int $value): int
    {
        if ($value & 0x80000000) {
            return $value | ~0xffffffff;
        }
        return $value;
    }
}
