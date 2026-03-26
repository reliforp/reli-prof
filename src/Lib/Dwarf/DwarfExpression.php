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
    private const DW_OP_LIT0 = 0x30;
    private const DW_OP_LIT31 = 0x4f;
    private const DW_OP_ADDR = 0x03;
    private const DW_OP_CONSTU = 0x10;
    private const DW_OP_CONSTS = 0x11;
    private const DW_OP_CONST1U = 0x08;
    private const DW_OP_CONST1S = 0x09;
    private const DW_OP_CONST2U = 0x0a;
    private const DW_OP_CONST2S = 0x0b;
    private const DW_OP_CONST4U = 0x0c;
    private const DW_OP_CONST4S = 0x0d;
    private const DW_OP_CONST8U = 0x0e;
    private const DW_OP_CONST8S = 0x0f;

    // Register-based operations
    private const DW_OP_BREG0 = 0x70;
    private const DW_OP_BREG31 = 0x8f;
    private const DW_OP_BREGX = 0x92;
    private const DW_OP_FBREG = 0x91;

    // Stack operations
    private const DW_OP_DUP = 0x12;
    private const DW_OP_DROP = 0x13;
    private const DW_OP_PICK = 0x15;
    private const DW_OP_OVER = 0x14;
    private const DW_OP_SWAP = 0x16;
    private const DW_OP_ROT = 0x17;

    // Arithmetic operations
    private const DW_OP_PLUS = 0x22;
    private const DW_OP_PLUS_UCONST = 0x23;
    private const DW_OP_MINUS = 0x1c;
    private const DW_OP_MUL = 0x1e;
    private const DW_OP_DIV = 0x1b;
    private const DW_OP_MOD = 0x1d;
    private const DW_OP_NEG = 0x1f;
    private const DW_OP_ABS = 0x19;
    private const DW_OP_NOT = 0x20;
    private const DW_OP_AND = 0x1a;
    private const DW_OP_OR = 0x21;
    private const DW_OP_XOR = 0x27;
    private const DW_OP_SHL = 0x24;
    private const DW_OP_SHR = 0x25;
    private const DW_OP_SHRA = 0x26;

    // Memory operations
    private const DW_OP_DEREF = 0x06;
    private const DW_OP_DEREF_SIZE = 0x94;

    // Control flow
    private const DW_OP_LE = 0x2c;
    private const DW_OP_GE = 0x2a;
    private const DW_OP_EQ = 0x29;
    private const DW_OP_LT = 0x2d;
    private const DW_OP_GT = 0x2b;
    private const DW_OP_NE = 0x2e;

    // Special
    private const DW_OP_NOP = 0x96;
    private const DW_OP_STACK_VALUE = 0x9f;

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

            // DW_OP_LIT0..DW_OP_LIT31
            if ($op >= self::DW_OP_LIT0 && $op <= self::DW_OP_LIT31) {
                $stack[] = $op - self::DW_OP_LIT0;
                continue;
            }

            // DW_OP_BREG0..DW_OP_BREG31
            if ($op >= self::DW_OP_BREG0 && $op <= self::DW_OP_BREG31) {
                $reg_num = $op - self::DW_OP_BREG0;
                [$sleb_offset, $consumed] = Leb128::decodeSigned($data, $offset);
                $offset += $consumed;
                $reg_value = $registers[$reg_num] ?? 0;
                $stack[] = $reg_value + $sleb_offset;
                continue;
            }

            /** @psalm-suppress PossiblyNullOperand,PossiblyNullArgument,PossiblyNullArrayOffset */
            match ($op) {
                self::DW_OP_ADDR => (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $stack[] = $integer_reader->read64($data, $offset)->toInt();
                    $offset += 8;
                })(),

                self::DW_OP_CONSTU => (function () use ($data, &$offset, &$stack) {
                    [$value, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $stack[] = $value;
                })(),

                self::DW_OP_CONSTS => (function () use ($data, &$offset, &$stack) {
                    [$value, $consumed] = Leb128::decodeSigned($data, $offset);
                    $offset += $consumed;
                    $stack[] = $value;
                })(),

                self::DW_OP_CONST1U => (function () use ($data, &$offset, &$stack) {
                    $stack[] = $data[$offset];
                    $offset++;
                })(),

                self::DW_OP_CONST1S => (function () use ($data, &$offset, &$stack) {
                    $val = $data[$offset];
                    $stack[] = ($val & 0x80) ? ($val | ~0xff) : $val;
                    $offset++;
                })(),

                self::DW_OP_CONST2U => (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $stack[] = $integer_reader->read16($data, $offset);
                    $offset += 2;
                })(),

                self::DW_OP_CONST2S => (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $val = $integer_reader->read16($data, $offset);
                    $stack[] = ($val & 0x8000) ? ($val | ~0xffff) : $val;
                    $offset += 2;
                })(),

                self::DW_OP_CONST4U => (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $stack[] = $integer_reader->read32($data, $offset);
                    $offset += 4;
                })(),

                self::DW_OP_CONST4S => (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $val = $integer_reader->read32($data, $offset);
                    $stack[] = ($val & 0x80000000) ? ($val | ~0xffffffff) : $val;
                    $offset += 4;
                })(),

                self::DW_OP_CONST8U, self::DW_OP_CONST8S =>
                (function () use ($data, &$offset, &$stack, $integer_reader) {
                    $stack[] = $integer_reader->read64($data, $offset)->toInt();
                    $offset += 8;
                })(),

                self::DW_OP_BREGX => (function () use ($data, &$offset, &$stack, $registers) {
                    [$reg_num, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$sleb_offset, $consumed] = Leb128::decodeSigned($data, $offset);
                    $offset += $consumed;
                    $reg_value = $registers[$reg_num] ?? 0;
                    $stack[] = $reg_value + $sleb_offset;
                })(),

                // Stack operations
                self::DW_OP_DUP => (function () use (&$stack) {
                    $stack[] = $stack[array_key_last($stack)];
                })(),

                self::DW_OP_DROP => (function () use (&$stack) {
                    array_pop($stack);
                })(),

                self::DW_OP_PICK => (function () use ($data, &$offset, &$stack) {
                    $index = $data[$offset];
                    $offset++;
                    $stack[] = $stack[count($stack) - 1 - $index];
                })(),

                self::DW_OP_OVER => (function () use (&$stack) {
                    $stack[] = $stack[count($stack) - 2];
                })(),

                self::DW_OP_SWAP => (function () use (&$stack) {
                    $count = count($stack);
                    [$stack[$count - 2], $stack[$count - 1]] = [$stack[$count - 1], $stack[$count - 2]];
                })(),

                self::DW_OP_ROT => (function () use (&$stack) {
                    $count = count($stack);
                    $top = $stack[$count - 1];
                    $stack[$count - 1] = $stack[$count - 2];
                    $stack[$count - 2] = $stack[$count - 3];
                    $stack[$count - 3] = $top;
                })(),

                // Arithmetic
                self::DW_OP_PLUS => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a + $b;
                })(),

                self::DW_OP_PLUS_UCONST => (function () use ($data, &$offset, &$stack) {
                    [$value, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $stack[array_key_last($stack)] += $value;
                })(),

                self::DW_OP_MINUS => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a - $b;
                })(),

                self::DW_OP_MUL => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a * $b;
                })(),

                self::DW_OP_DIV => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = intdiv($a, $b);
                })(),

                self::DW_OP_MOD => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a % $b;
                })(),

                self::DW_OP_NEG => (function () use (&$stack) {
                    $stack[array_key_last($stack)] = -$stack[array_key_last($stack)];
                })(),

                self::DW_OP_ABS => (function () use (&$stack) {
                    $key = array_key_last($stack);
                    if ($stack[$key] < 0) {
                        $stack[$key] = -$stack[$key];
                    }
                })(),

                self::DW_OP_NOT => (function () use (&$stack) {
                    $stack[array_key_last($stack)] = ~$stack[array_key_last($stack)];
                })(),

                self::DW_OP_AND => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a & $b;
                })(),

                self::DW_OP_OR => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a | $b;
                })(),

                self::DW_OP_XOR => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a ^ $b;
                })(),

                self::DW_OP_SHL => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a << $b;
                })(),

                self::DW_OP_SHR => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    // Logical right shift
                    $stack[] = ($a >> $b) & (PHP_INT_MAX >> ($b - 1));
                })(),

                self::DW_OP_SHRA => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = $a >> $b;
                })(),

                // Comparison
                self::DW_OP_EQ => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a === $b) ? 1 : 0;
                })(),

                self::DW_OP_NE => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a !== $b) ? 1 : 0;
                })(),

                self::DW_OP_LT => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a < $b) ? 1 : 0;
                })(),

                self::DW_OP_GT => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a > $b) ? 1 : 0;
                })(),

                self::DW_OP_LE => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a <= $b) ? 1 : 0;
                })(),

                self::DW_OP_GE => (function () use (&$stack) {
                    $b = array_pop($stack);
                    $a = array_pop($stack);
                    $stack[] = ($a >= $b) ? 1 : 0;
                })(),

                // Memory operations
                self::DW_OP_DEREF => (function () use (&$stack, $memoryReader, $pid) {
                    $addr = array_pop($stack);
                    if ($memoryReader !== null) {
                        $buf = $memoryReader->read($pid, $addr, 8);
                        $reader = new LittleEndianReader();
                        $stack[] = $reader->read64(new CDataByteReader($buf), 0)->toInt();
                    } else {
                        throw new DwarfException('DW_OP_DEREF requires memory reader');
                    }
                })(),

                self::DW_OP_DEREF_SIZE => (function () use ($data, &$offset, &$stack, $memoryReader, $pid) {
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
                        throw new DwarfException('DW_OP_DEREF_SIZE requires memory reader');
                    }
                })(),

                self::DW_OP_NOP => null,
                self::DW_OP_STACK_VALUE => null, // marker that result is the value itself

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
