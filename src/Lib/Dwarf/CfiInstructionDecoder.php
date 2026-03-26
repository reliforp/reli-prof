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
use Reli\Lib\ByteStream\StringByteReader;

final class CfiInstructionDecoder
{
    // High 2 bits opcodes
    private const DW_CFA_ADVANCE_LOC = 0x40;
    private const DW_CFA_OFFSET = 0x80;
    private const DW_CFA_RESTORE = 0xc0;

    // Low 6 bits opcodes
    private const DW_CFA_NOP = 0x00;
    private const DW_CFA_SET_LOC = 0x01;
    private const DW_CFA_ADVANCE_LOC1 = 0x02;
    private const DW_CFA_ADVANCE_LOC2 = 0x03;
    private const DW_CFA_ADVANCE_LOC4 = 0x04;
    private const DW_CFA_OFFSET_EXTENDED = 0x05;
    private const DW_CFA_RESTORE_EXTENDED = 0x06;
    private const DW_CFA_UNDEFINED = 0x07;
    private const DW_CFA_SAME_VALUE = 0x08;
    private const DW_CFA_REGISTER = 0x09;
    private const DW_CFA_REMEMBER_STATE = 0x0a;
    private const DW_CFA_RESTORE_STATE = 0x0b;
    private const DW_CFA_DEF_CFA = 0x0c;
    private const DW_CFA_DEF_CFA_REGISTER = 0x0d;
    private const DW_CFA_DEF_CFA_OFFSET = 0x0e;
    private const DW_CFA_DEF_CFA_EXPRESSION = 0x0f;
    private const DW_CFA_EXPRESSION = 0x10;
    private const DW_CFA_OFFSET_EXTENDED_SF = 0x11;
    private const DW_CFA_DEF_CFA_SF = 0x12;
    private const DW_CFA_DEF_CFA_OFFSET_SF = 0x13;
    private const DW_CFA_VAL_OFFSET = 0x14;
    private const DW_CFA_VAL_OFFSET_SF = 0x15;
    private const DW_CFA_VAL_EXPRESSION = 0x16;
    private const DW_CFA_GNU_ARGS_SIZE = 0x2e;
    private const DW_CFA_GNU_NEGATIVE_OFFSET_EXTENDED = 0x2f;

    /**
     * @return array<UnwindTableRow>
     */
    public function execute(
        string $instructions,
        Cie $cie,
        int $initialLocation,
        ?UnwindTableRow $initialRow = null,
    ): array {
        $data = new StringByteReader($instructions);
        $length = strlen($instructions);
        $offset = 0;

        $cfa_rule = $initialRow?->cfaRule ?? CfaRule::registerOffset(0, 0);
        $register_rules = $initialRow?->registerRules ?? [];
        $location = $initialLocation;
        /** @var array<UnwindTableRow> $rows */
        $rows = [];
        /** @var array<array{CfaRule, array<int, RegisterRule>}> $state_stack */
        $state_stack = [];
        /** @var array<int, RegisterRule>|null $initial_register_rules */
        $initial_register_rules = $initialRow !== null ? $initialRow->registerRules : null;

        $code_align = $cie->codeAlignmentFactor;
        $data_align = $cie->dataAlignmentFactor;

        while ($offset < $length) {
            $byte = $data[$offset];
            $offset++;

            $high2 = $byte & 0xc0;
            $low6 = $byte & 0x3f;

            if ($high2 === self::DW_CFA_ADVANCE_LOC) {
                $rows[] = new UnwindTableRow($location, $cfa_rule, $register_rules);
                $location += $low6 * $code_align;
                continue;
            }

            if ($high2 === self::DW_CFA_OFFSET) {
                $register = $low6;
                [$uleb_value, $consumed] = Leb128::decodeUnsigned($data, $offset);
                $offset += $consumed;
                $register_rules[$register] = RegisterRule::offset($uleb_value * $data_align);
                continue;
            }

            if ($high2 === self::DW_CFA_RESTORE) {
                $register = $low6;
                if ($initial_register_rules !== null && isset($initial_register_rules[$register])) {
                    $register_rules[$register] = $initial_register_rules[$register];
                } else {
                    unset($register_rules[$register]);
                }
                continue;
            }

            // Extended opcodes
            match ($low6) {
                self::DW_CFA_NOP => null,

                self::DW_CFA_SET_LOC =>
                (function () use ($data, &$offset, &$rows, &$location, $cfa_rule, $register_rules) {
                    $rows[] = new UnwindTableRow($location, $cfa_rule, $register_rules);
                    [$location, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                })(),

                self::DW_CFA_ADVANCE_LOC1 =>
                (function () use ($data, &$offset, &$rows, &$location, $cfa_rule, $register_rules, $code_align) {
                    $rows[] = new UnwindTableRow($location, $cfa_rule, $register_rules);
                    $location += $data[$offset] * $code_align;
                    $offset++;
                })(),

                self::DW_CFA_ADVANCE_LOC2 =>
                (function () use ($data, &$offset, &$rows, &$location, $cfa_rule, $register_rules, $code_align) {
                    $rows[] = new UnwindTableRow($location, $cfa_rule, $register_rules);
                    $location += ($data[$offset] | ($data[$offset + 1] << 8)) * $code_align;
                    $offset += 2;
                })(),

                self::DW_CFA_ADVANCE_LOC4 =>
                (function () use ($data, &$offset, &$rows, &$location, $cfa_rule, $register_rules, $code_align) {
                    $rows[] = new UnwindTableRow($location, $cfa_rule, $register_rules);
                    $location += ($data[$offset] | ($data[$offset + 1] << 8)
                        | ($data[$offset + 2] << 16) | ($data[$offset + 3] << 24)) * $code_align;
                    $offset += 4;
                })(),

                self::DW_CFA_OFFSET_EXTENDED => (function () use ($data, &$offset, &$register_rules, $data_align) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$uleb_value, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $register_rules[$register] = RegisterRule::offset($uleb_value * $data_align);
                })(),

                self::DW_CFA_RESTORE_EXTENDED =>
                (function () use ($data, &$offset, &$register_rules, $initial_register_rules) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    if ($initial_register_rules !== null && isset($initial_register_rules[$register])) {
                        $register_rules[$register] = $initial_register_rules[$register];
                    } else {
                        unset($register_rules[$register]);
                    }
                })(),

                self::DW_CFA_UNDEFINED => (function () use ($data, &$offset, &$register_rules) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $register_rules[$register] = RegisterRule::undefined();
                })(),

                self::DW_CFA_SAME_VALUE => (function () use ($data, &$offset, &$register_rules) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $register_rules[$register] = RegisterRule::sameValue();
                })(),

                self::DW_CFA_REGISTER => (function () use ($data, &$offset, &$register_rules) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$target_register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $register_rules[$register] = RegisterRule::register($target_register);
                })(),

                self::DW_CFA_REMEMBER_STATE => (function () use (&$state_stack, $cfa_rule, $register_rules) {
                    $state_stack[] = [$cfa_rule, $register_rules];
                })(),

                self::DW_CFA_RESTORE_STATE => (function () use (&$state_stack, &$cfa_rule, &$register_rules) {
                    if ($state_stack !== []) {
                        [$cfa_rule, $register_rules] = array_pop($state_stack);
                    }
                })(),

                self::DW_CFA_DEF_CFA => (function () use ($data, &$offset, &$cfa_rule) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$cfa_offset, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $cfa_rule = CfaRule::registerOffset($register, $cfa_offset);
                })(),

                self::DW_CFA_DEF_CFA_REGISTER => (function () use ($data, &$offset, &$cfa_rule) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $cfa_rule = CfaRule::registerOffset($register, $cfa_rule->offset);
                })(),

                self::DW_CFA_DEF_CFA_OFFSET => (function () use ($data, &$offset, &$cfa_rule) {
                    [$cfa_offset, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $cfa_rule = CfaRule::registerOffset($cfa_rule->register, $cfa_offset);
                })(),

                self::DW_CFA_DEF_CFA_EXPRESSION => (function () use ($data, &$offset, &$cfa_rule) {
                    [$expr_length, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $expr_bytes = '';
                    for ($i = 0; $i < $expr_length; $i++) {
                        $expr_bytes .= chr($data[$offset + $i]);
                    }
                    $offset += $expr_length;
                    $cfa_rule = CfaRule::expression($expr_bytes);
                })(),

                self::DW_CFA_EXPRESSION => (function () use ($data, &$offset, &$register_rules) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$expr_length, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $expr_bytes = '';
                    for ($i = 0; $i < $expr_length; $i++) {
                        $expr_bytes .= chr($data[$offset + $i]);
                    }
                    $offset += $expr_length;
                    $register_rules[$register] = RegisterRule::expression($expr_bytes);
                })(),

                self::DW_CFA_OFFSET_EXTENDED_SF => (function () use ($data, &$offset, &$register_rules, $data_align) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$sleb_value, $consumed] = Leb128::decodeSigned($data, $offset);
                    $offset += $consumed;
                    $register_rules[$register] = RegisterRule::offset($sleb_value * $data_align);
                })(),

                self::DW_CFA_DEF_CFA_SF => (function () use ($data, &$offset, &$cfa_rule, $data_align) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$sleb_value, $consumed] = Leb128::decodeSigned($data, $offset);
                    $offset += $consumed;
                    $cfa_rule = CfaRule::registerOffset($register, $sleb_value * $data_align);
                })(),

                self::DW_CFA_DEF_CFA_OFFSET_SF => (function () use ($data, &$offset, &$cfa_rule, $data_align) {
                    [$sleb_value, $consumed] = Leb128::decodeSigned($data, $offset);
                    $offset += $consumed;
                    $cfa_rule = CfaRule::registerOffset($cfa_rule->register, $sleb_value * $data_align);
                })(),

                self::DW_CFA_VAL_OFFSET => (function () use ($data, &$offset, &$register_rules, $data_align) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$uleb_value, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $register_rules[$register] = RegisterRule::valOffset($uleb_value * $data_align);
                })(),

                self::DW_CFA_VAL_OFFSET_SF => (function () use ($data, &$offset, &$register_rules, $data_align) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$sleb_value, $consumed] = Leb128::decodeSigned($data, $offset);
                    $offset += $consumed;
                    $register_rules[$register] = RegisterRule::valOffset($sleb_value * $data_align);
                })(),

                self::DW_CFA_VAL_EXPRESSION => (function () use ($data, &$offset, &$register_rules) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$expr_length, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $expr_bytes = '';
                    for ($i = 0; $i < $expr_length; $i++) {
                        $expr_bytes .= chr($data[$offset + $i]);
                    }
                    $offset += $expr_length;
                    $register_rules[$register] = RegisterRule::valExpression($expr_bytes);
                })(),

                self::DW_CFA_GNU_ARGS_SIZE => (function () use ($data, &$offset) {
                    [, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                })(),

                self::DW_CFA_GNU_NEGATIVE_OFFSET_EXTENDED =>
                (function () use ($data, &$offset, &$register_rules, $data_align) {
                    [$register, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    [$uleb_value, $consumed] = Leb128::decodeUnsigned($data, $offset);
                    $offset += $consumed;
                    $register_rules[$register] = RegisterRule::offset(-$uleb_value * $data_align);
                })(),

                default => throw new DwarfException(
                    sprintf('unsupported CFI instruction: 0x%02x at offset %d', $byte, $offset - 1)
                ),
            };
        }

        // Add final row
        $rows[] = new UnwindTableRow($location, $cfa_rule, $register_rules);

        return $rows;
    }

    public function buildInitialRow(Cie $cie): UnwindTableRow
    {
        $rows = $this->execute($cie->initialInstructions, $cie, 0);
        $lastKey = array_key_last($rows);
        return $lastKey !== null ? $rows[$lastKey] : new UnwindTableRow(0, CfaRule::registerOffset(0, 0), []);
    }
}
