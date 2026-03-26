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
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;

final class EhFrameParser
{
    public function __construct(
        private IntegerByteSequenceReader $integerReader,
    ) {
    }

    /**
     * @return array<Fde>
     */
    public function parse(
        ByteReaderInterface $data,
        int $sectionOffset,
        int $sectionSize,
        int $sectionVaddr,
    ): array {
        /** @var array<int, Cie> $cies */
        $cies = [];
        /** @var array<Fde> $fdes */
        $fdes = [];

        $offset = 0;

        while ($offset < $sectionSize) {
            $entry_start = $offset;

            // Read length (4 bytes, or 12 if extended)
            $length = $this->integerReader->read32($data, $sectionOffset + $offset);
            $offset += 4;

            if ($length === 0) {
                break; // terminator
            }

            if ($length === 0xffffffff) {
                // 64-bit DWARF - read 8 byte length
                $length = $this->integerReader->read64($data, $sectionOffset + $offset)->toInt();
                $offset += 8;
            }

            $entry_data_start = $offset;
            $entry_end = $entry_data_start + $length;

            // CIE_id / CIE_pointer
            $cie_id = $this->integerReader->read32($data, $sectionOffset + $offset);
            $offset += 4;

            if ($cie_id === 0) {
                // This is a CIE
                $cie = $this->parseCie($data, $sectionOffset, $offset, $entry_end, $entry_start);
                $cies[$entry_start] = $cie;
            } else {
                // This is an FDE - cie_id is offset to CIE from current position
                $cie_pointer = $entry_data_start - $cie_id;
                $cie = $cies[$cie_pointer] ?? null;
                if ($cie !== null) {
                    $fde = $this->parseFde(
                        $data,
                        $sectionOffset,
                        $offset,
                        $entry_end,
                        $cie,
                        $sectionVaddr + $offset,
                    );
                    if ($fde !== null) {
                        $fdes[] = $fde;
                    }
                }
            }

            $offset = $entry_end;
        }

        return $fdes;
    }

    private function parseCie(
        ByteReaderInterface $data,
        int $sectionOffset,
        int $offset,
        int $entryEnd,
        int $entryStart,
    ): Cie {
        $base = $sectionOffset;

        // Version
        $version = $data[$base + $offset];
        $offset++;

        // Augmentation string (null-terminated)
        $augmentation = '';
        while ($offset < $entryEnd && $data[$base + $offset] !== 0) {
            $augmentation .= chr($data[$base + $offset]);
            $offset++;
        }
        $offset++; // skip null terminator

        // Code alignment factor (ULEB128)
        [$code_alignment_factor, $consumed] = Leb128::decodeUnsigned($data, $base + $offset);
        $offset += $consumed;

        // Data alignment factor (SLEB128)
        [$data_alignment_factor, $consumed] = Leb128::decodeSigned($data, $base + $offset);
        $offset += $consumed;

        // Return address register
        if ($version === 1) {
            $return_address_register = $data[$base + $offset];
            $offset++;
        } else {
            [$return_address_register, $consumed] = Leb128::decodeUnsigned($data, $base + $offset);
            $offset += $consumed;
        }

        // Augmentation data (if augmentation string starts with 'z')
        $fde_encoding = DwarfPointerEncoding::DW_EH_PE_absptr;
        $lsda_encoding = null;
        $personality_encoding = null;
        $personality_address = null;

        if (str_starts_with($augmentation, 'z')) {
            [$aug_data_length, $consumed] = Leb128::decodeUnsigned($data, $base + $offset);
            $offset += $consumed;
            $aug_data_end = $offset + $aug_data_length;

            // Parse augmentation data based on augmentation string characters (skip 'z')
            for ($i = 1; $i < strlen($augmentation) && $offset < $aug_data_end; $i++) {
                $ch = $augmentation[$i];
                match ($ch) {
                    'R' => (function () use ($data, $base, &$offset, &$fde_encoding) {
                        $fde_encoding = $data[$base + $offset];
                        $offset++;
                    })(),
                    'P' => (function () use ($data, $base, &$offset, &$personality_encoding, &$personality_address) {
                        $personality_encoding = $data[$base + $offset];
                        $offset++;
                        [$personality_address, $consumed] = DwarfPointerEncoding::decode(
                            $data,
                            $base + $offset,
                            $personality_encoding,
                            $base + $offset,
                            new LittleEndianReader(),
                        );
                        $offset += $consumed;
                    })(),
                    'L' => (function () use ($data, $base, &$offset, &$lsda_encoding) {
                        $lsda_encoding = $data[$base + $offset];
                        $offset++;
                    })(),
                    'S' => null, // Signal frame - no data to consume
                    default => null,
                };
            }

            $offset = $aug_data_end;
        }

        // Initial instructions (remaining bytes)
        $instructions = '';
        for ($i = $offset; $i < $entryEnd; $i++) {
            $instructions .= chr($data[$base + $i]);
        }

        return new Cie(
            $code_alignment_factor,
            $data_alignment_factor,
            $return_address_register,
            $instructions,
            $augmentation,
            $fde_encoding,
            $lsda_encoding,
            $personality_encoding,
            $personality_address,
        );
    }

    private function parseFde(
        ByteReaderInterface $data,
        int $sectionOffset,
        int $offset,
        int $entryEnd,
        Cie $cie,
        int $pcRelBase,
    ): ?Fde {
        $base = $sectionOffset;

        // Initial location (encoded)
        [$initial_location, $consumed] = DwarfPointerEncoding::decode(
            $data,
            $base + $offset,
            $cie->fdeEncoding,
            $pcRelBase + $sectionOffset,
            $this->integerReader,
        );
        $offset += $consumed;

        // Address range (same encoding format but always absolute for the size)
        $range_format = $cie->fdeEncoding & 0x0f;
        [$address_range, $consumed] = DwarfPointerEncoding::decode(
            $data,
            $base + $offset,
            $range_format, // size uses format only, no application modifier
            0,
            $this->integerReader,
        );
        $offset += $consumed;

        if ($initial_location === 0 && $address_range === 0) {
            return null;
        }

        // Augmentation data (if CIE has 'z' augmentation)
        if (str_starts_with($cie->augmentation, 'z')) {
            [$aug_data_length, $consumed] = Leb128::decodeUnsigned($data, $base + $offset);
            $offset += $consumed;
            $offset += $aug_data_length; // skip LSDA pointer etc.
        }

        // Instructions (remaining bytes)
        $instructions = '';
        for ($i = $offset; $i < $entryEnd; $i++) {
            $instructions .= chr($data[$base + $i]);
        }

        return new Fde(
            $initial_location,
            $address_range,
            $instructions,
            $cie,
        );
    }
}
