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
    public const DW_EH_PE_absptr = 0x00;
    public const DW_EH_PE_uleb128 = 0x01;
    public const DW_EH_PE_udata2 = 0x02;
    public const DW_EH_PE_udata4 = 0x03;
    public const DW_EH_PE_udata8 = 0x04;
    public const DW_EH_PE_sleb128 = 0x09;
    public const DW_EH_PE_sdata2 = 0x0a;
    public const DW_EH_PE_sdata4 = 0x0b;
    public const DW_EH_PE_sdata8 = 0x0c;

    public const DW_EH_PE_pcrel = 0x10;
    public const DW_EH_PE_textrel = 0x20;
    public const DW_EH_PE_datarel = 0x30;
    public const DW_EH_PE_funcrel = 0x40;
    public const DW_EH_PE_aligned = 0x50;

    public const DW_EH_PE_indirect = 0x80;
    public const DW_EH_PE_omit = 0xff;

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
        if ($encoding === self::DW_EH_PE_omit) {
            return [0, 0];
        }

        $format = $encoding & 0x0f;
        $application = $encoding & 0x70;

        [$raw_value, $bytes_consumed] = self::decodeValue($data, $offset, $format, $integer_reader);

        $value = match ($application) {
            self::DW_EH_PE_pcrel => $raw_value + $pcRelBase,
            0x00 => $raw_value, // DW_EH_PE_absptr application
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
            self::DW_EH_PE_absptr => [
                $integer_reader->read64($data, $offset)->toInt(),
                8,
            ],
            self::DW_EH_PE_uleb128 => Leb128::decodeUnsigned($data, $offset),
            self::DW_EH_PE_udata2 => [
                $integer_reader->read16($data, $offset),
                2,
            ],
            self::DW_EH_PE_udata4 => [
                $integer_reader->read32($data, $offset),
                4,
            ],
            self::DW_EH_PE_udata8 => [
                $integer_reader->read64($data, $offset)->toInt(),
                8,
            ],
            self::DW_EH_PE_sleb128 => Leb128::decodeSigned($data, $offset),
            self::DW_EH_PE_sdata2 => [
                self::signExtend16($integer_reader->read16($data, $offset)),
                2,
            ],
            self::DW_EH_PE_sdata4 => [
                self::signExtend32($integer_reader->read32($data, $offset)),
                4,
            ],
            self::DW_EH_PE_sdata8 => [
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
