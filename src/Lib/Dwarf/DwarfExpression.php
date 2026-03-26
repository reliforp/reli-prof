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

use Reli\Lib\ByteStream\CDataByteReader;
use Reli\Lib\ByteStream\IntegerByteSequence\IntegerByteSequenceReader;
use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;

final class DwarfExpression
{
    // Literal encodings
    private const DW_OP_lit0 = 0x30;
    private const DW_OP_lit31 = 0x4f;
    private const DW_OP_addr = 0x03;
    private const DW_OP_constu = 0x10;
    private const DW_OP_consts = 0x11;
    private const DW_OP_const1u = 0x08;
    private const DW_OP_const1s = 0x09;
    private const DW_OP_const2u = 0x0a;
    private const DW_OP_const2s = 0x0b;
    private const DW_OP_const4u = 0x0c;
    private const DW_OP_const4s = 0x0d;
    private const DW_OP_const8u = 0x0e;
    private const DW_OP_const8s = 0x0f;

    // Register-based operations
    private const DW_OP_breg0 = 0x70;
    private const DW_OP_breg31 = 0x8f;
    private const DW_OP_bregx = 0x92;
    private const DW_OP_fbreg = 0x91;

    // Stack operations
    private const DW_OP_dup = 0x12;
    private const DW_OP_drop = 0x13;
    private const DW_OP_pick = 0x15;
    private const DW_OP_over = 0x14;
    private const DW_OP_swap = 0x16;
    private const DW_OP_rot = 0x17;

    // Arithmetic operations
    private const DW_OP_plus = 0x22;
    private const DW_OP_plus_uconst = 0x23;
    private const DW_OP_minus = 0x1c;
    private const DW_OP_mul = 0x1e;
    private const DW_OP_div = 0x1b;
    private const DW_OP_mod = 0x1d;
    private const DW_OP_neg = 0x1f;
    private const DW_OP_abs = 0x19;
    private const DW_OP_not = 0x20;
    private const DW_OP_and = 0x1a;
    private const DW_OP_or = 0x21;
    private const DW_OP_xor = 0x27;
    private const DW_OP_shl = 0x24;
    private const DW_OP_shr = 0x25;
    private const DW_OP_shra = 0x26;

    // Memory operations
    private const DW_OP_deref = 0x06;
    private const DW_OP_deref_size = 0x94;

    // Control flow
    private const DW_OP_le = 0x2c;
    private const DW_OP_ge = 0x2a;
    private const DW_OP_eq = 0x29;
    private const DW_OP_lt = 0x2d;
    private const DW_OP_gt = 0x2b;
    private const DW_OP_ne = 0x2e;

    // Special
    private const DW_OP_nop = 0x96;
    private const DW_OP_stack_value = 0x9f;

    /**
     * @param array<int, int> $registers DWARF register number => value
     */
    public function evaluate(
        string $bytes,
        array $registers,
        ?MemoryReaderInterface $memoryReader = null,
        int $pid = 0,
    ): int {
        $data = new StringByteReader($bytes);
        $integer_reader = new LittleEndianReader();
        $length = strlen($bytes);
        $offset = 0;
        /** @var int[] $stack */
        $stack = [];

        while ($offset < $length) {
            $op = $data[$offset];
            $offset++;

            // DW_OP_lit0..DW_OP_lit31
            if ($op >= self::DW_OP_lit0 && $op <= self::DW_OP_lit31) {
                $stack[] = $op - self::DW_OP_lit0;
                continue;
            }

            // DW_OP_breg0..DW_OP_breg31
            if ($op >= self::DW_OP_breg0 && $op <= self::DW_OP_breg31) {
                $reg_num = $op - self::DW_OP_breg0;
                [$sleb_offset, $consumed] = Leb128::decodeSigned($data, $offset);
                $offset += $consumed;
                $reg_value = $registers[$reg_num] ?? 0;
                $stack[] = $reg_value + $sleb_offset;
                continue;
            }

            match ($op) {
                self::DW_OP_addr => (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $stack[] = $integer_reader->read64($data, $offset)->toInt();
                    $offset += 8;
                })(),

                self::DW_OP_constu => (function () use ($data, &$offset, &$stack) {
                    [$value, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $stack[] = $value;
                })(),

                self::DW_OP_consts => (function () use ($data, &$offset, &$stack) {
                    [$value, $consumed] = Leb128::decodeSigned($data, $offset);
                    $offset += $consumed;
                    $stack[] = $value;
                })(),

                self::DW_OP_const1u => (function () use ($data, &$offset, &$stack) {
                    $stack[] = $data[$offset];
                    $offset++;
                })(),

                self::DW_OP_const1s => (function () use ($data, &$offset, &$stack) {
                    $val = $data[$offset];
                    $stack[] = ($val & 0x80) ? ($val | ~0xff) : $val;
                    $offset++;
                })(),

                self::DW_OP_const2u => (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $stack[] = $integer_reader->read16($data, $offset);
                    $offset += 2;
                })(),

                self::DW_OP_const2s => (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $val = $integer_reader->read16($data, $offset);
                    $stack[] = ($val & 0x8000) ? ($val | ~0xffff) : $val;
                    $offset += 2;
                })(),

                self::DW_OP_const4u => (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $stack[] = $integer_reader->read32($data, $offset);
                    $offset += 4;
                })(),

                self::DW_OP_const4s => (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $val = $integer_reader->read32($data, $offset);
                    $stack[] = ($val & 0x80000000) ? ($val | ~0xffffffff) : $val;
                    $offset += 4;
                })(),

                self::DW_OP_const8u, self::DW_OP_const8s => (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $stack[] = $integer_reader->read64($data, $offset)->toInt();
                    $offset += 8;
                })(),

                self::DW_OP_bregx => (function () use ($data, &$offset, &$stack, $registers) {
                    [$reg_num, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$sleb_offset, $consumed] = Leb128::decodeSigned($data, $offset);
                    $offset += $consumed;
                    $reg_value = $registers[$reg_num] ?? 0;
                    $stack[] = $reg_value + $sleb_offset;
                })(),

                // Stack operations
                self::DW_OP_dup => (function () use (&$stack) {
                    $stack[] = $stack[array_key_last($stack)];
                })(),

                self::DW_OP_drop => (function () use (&$stack) {
                    array_pop($stack);
                })(),

                self::DW_OP_pick => (function () use ($data, &$offset, &$stack) {
                    $index = $data[$offset];
                    $offset++;
                    $stack[] = $stack[count($stack) - 1 - $index];
                })(),

                self::DW_OP_over => (function () use (&$stack) {
                    $stack[] = $stack[count($stack) - 2];
                })(),

                self::DW_OP_swap => (function () use (&$stack) {
                    $count = count($stack);
                    [$stack[$count - 2], $stack[$count - 1]] = [$stack[$count - 1], $stack[$count - 2]];
                })(),

                self::DW_OP_rot => (function () use (&$stack) {
                    $count = count($stack);
                    $top = $stack[$count - 1];
                    $stack[$count - 1] = $stack[$count - 2];
                    $stack[$count - 2] = $stack[$count - 3];
                    $stack[$count - 3] = $top;
                })(),

                // Arithmetic
                self::DW_OP_plus => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a + $b;
                })(),

                self::DW_OP_plus_uconst => (function () use ($data, &$offset, &$stack) {
                    [$value, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $stack[array_key_last($stack)] += $value;
                })(),

                self::DW_OP_minus => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a - $b;
                })(),

                self::DW_OP_mul => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a * $b;
                })(),

                self::DW_OP_div => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = intdiv($a, $b);
                })(),

                self::DW_OP_mod => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a % $b;
                })(),

                self::DW_OP_neg => (function () use (&$stack) {
                    $stack[array_key_last($stack)] = -$stack[array_key_last($stack)];
                })(),

                self::DW_OP_abs => (function () use (&$stack) {
                    $key = array_key_last($stack);
                    if ($stack[$key] < 0) {
                        $stack[$key] = -$stack[$key];
                    }
                })(),

                self::DW_OP_not => (function () use (&$stack) {
                    $stack[array_key_last($stack)] = ~$stack[array_key_last($stack)];
                })(),

                self::DW_OP_and => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a & $b;
                })(),

                self::DW_OP_or => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a | $b;
                })(),

                self::DW_OP_xor => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a ^ $b;
                })(),

                self::DW_OP_shl => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a << $b;
                })(),

                self::DW_OP_shr => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    // Logical right shift
                    $stack[] = ($a >> $b) & (PHP_INT_MAX >> ($b - 1));
                })(),

                self::DW_OP_shra => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a >> $b;
                })(),

                // Comparison
                self::DW_OP_eq => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a === $b) ? 1 : 0;
                })(),

                self::DW_OP_ne => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a !== $b) ? 1 : 0;
                })(),

                self::DW_OP_lt => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a < $b) ? 1 : 0;
                })(),

                self::DW_OP_gt => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a > $b) ? 1 : 0;
                })(),

                self::DW_OP_le => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a <= $b) ? 1 : 0;
                })(),

                self::DW_OP_ge => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a >= $b) ? 1 : 0;
                })(),

                // Memory operations
                self::DW_OP_deref => (function () use (&$stack, $memoryReader, $pid) {
                    $addr = array_pop($stack);
                    if ($memoryReader !== null) {
                        $buf = $memoryReader->read($pid, $addr, 8);
                        $reader = new LittleEndianReader();
                        $stack[] = $reader->read64(new CDataByteReader($buf), 0)->toInt();
                    } else {
                        throw new DwarfException('DW_OP_deref requires memory reader');
                    }
                })(),

                self::DW_OP_deref_size => (function () use ($data, &$offset, &$stack, $memoryReader, $pid) {
                    $size = $data[$offset];
                    $offset++;
                    $addr = array_pop($stack);
                    if ($memoryReader !== null) {
                        $buf = $memoryReader->read($pid, $addr, $size);
                        $reader = new LittleEndianReader();
                        $value = match ($size) {
                            1 => $reader->read8(new CDataByteReader($buf), 0),
                            2 => $reader->read16(new CDataByteReader($buf), 0),
                            4 => $reader->read32(new CDataByteReader($buf), 0),
                            8 => $reader->read64(new CDataByteReader($buf), 0)->toInt(),
                            default => throw new DwarfException("unsupported deref_size: {$size}"),
                        };
                        $stack[] = $value;
                    } else {
                        throw new DwarfException('DW_OP_deref_size requires memory reader');
                    }
                })(),

                self::DW_OP_nop => null,
                self::DW_OP_stack_value => null, // marker that result is the value itself

                default => throw new DwarfException(
                    sprintf('unsupported DWARF expression op: 0x%02x', $op)
                ),
            };
        }

        if ($stack === []) {
            throw new DwarfException('DWARF expression stack is empty after evaluation');
        }

        return $stack[array_key_last($stack)];
    }
}
