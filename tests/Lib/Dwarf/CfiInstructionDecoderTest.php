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

use Reli\BaseTestCase;

class CfiInstructionDecoderTest extends BaseTestCase
{
    private CfiInstructionDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new CfiInstructionDecoder();
    }

    private function makeCie(
        int $codeAlign = 1,
        int $dataAlign = -8,
        int $raRegister = 16,
    ): Cie {
        return new Cie($codeAlign, $dataAlign, $raRegister, '', 'zR', 0, null, null, null);
    }

    public function testDefCfa(): void
    {
        // DW_CFA_def_cfa reg=7 offset=8
        // 0x0c 0x07 0x08
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x0c\x07\x08", $cie, 0x1000);

        $this->assertNotEmpty($rows);
        $last = $rows[array_key_last($rows)];
        $this->assertSame(CfaRuleType::RegisterOffset, $last->cfaRule->type);
        $this->assertSame(7, $last->cfaRule->register);
        $this->assertSame(8, $last->cfaRule->offset);
    }

    public function testDefCfaOffset(): void
    {
        // DW_CFA_def_cfa reg=7 offset=8, then DW_CFA_def_cfa_offset 16
        // 0x0c 0x07 0x08 0x0e 0x10
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x0c\x07\x08\x0e\x10", $cie, 0x1000);

        $last = $rows[array_key_last($rows)];
        $this->assertSame(7, $last->cfaRule->register);
        $this->assertSame(16, $last->cfaRule->offset);
    }

    public function testDefCfaRegister(): void
    {
        // DW_CFA_def_cfa reg=7 offset=8, then DW_CFA_def_cfa_register 6
        // 0x0c 0x07 0x08 0x0d 0x06
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x0c\x07\x08\x0d\x06", $cie, 0x1000);

        $last = $rows[array_key_last($rows)];
        $this->assertSame(6, $last->cfaRule->register);
        $this->assertSame(8, $last->cfaRule->offset);
    }

    public function testOffset(): void
    {
        // DW_CFA_offset reg=16 offset=1 (factored by data_alignment=-8 → -8)
        // High 2 bits = 10, low 6 = 16 → 0x90, ULEB(1) = 0x01
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x90\x01", $cie, 0x1000);

        $last = $rows[array_key_last($rows)];
        $rule = $last->getRegisterRule(16);
        $this->assertSame(RegisterRuleType::Offset, $rule->type);
        $this->assertSame(-8, $rule->value); // 1 * data_align(-8)
    }

    public function testAdvanceLoc(): void
    {
        // DW_CFA_def_cfa reg=7 offset=8
        // DW_CFA_advance_loc(4) → high 2 bits = 01, low 6 = 4 → 0x44
        // DW_CFA_def_cfa_offset 16
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x0c\x07\x08\x44\x0e\x10", $cie, 0x1000);

        // Should have at least 2 rows
        $this->assertGreaterThanOrEqual(2, count($rows));
        // First row at 0x1000 with offset=8
        $this->assertSame(0x1000, $rows[0]->location);
        $this->assertSame(8, $rows[0]->cfaRule->offset);
        // After advance_loc(4), location = 0x1004
        $this->assertSame(0x1004, $rows[1]->location);
        $this->assertSame(16, $rows[1]->cfaRule->offset);
    }

    public function testRememberRestoreState(): void
    {
        // DW_CFA_def_cfa 7,8 → DW_CFA_remember_state → DW_CFA_def_cfa_offset 32 → DW_CFA_restore_state
        // 0x0c 0x07 0x08 0x0a 0x0e 0x20 0x0b
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x0c\x07\x08\x0a\x0e\x20\x0b", $cie, 0x1000);

        $last = $rows[array_key_last($rows)];
        // After restore_state, should be back to offset=8
        $this->assertSame(8, $last->cfaRule->offset);
    }

    public function testBuildInitialRow(): void
    {
        // CIE with initial instructions: DW_CFA_def_cfa 7,8 + DW_CFA_offset 16,1
        $cie = new Cie(1, -8, 16, "\x0c\x07\x08\x90\x01", 'zR', 0, null, null, null);
        $row = $this->decoder->buildInitialRow($cie);

        $this->assertSame(CfaRuleType::RegisterOffset, $row->cfaRule->type);
        $this->assertSame(7, $row->cfaRule->register);
        $this->assertSame(8, $row->cfaRule->offset);
        $this->assertSame(-8, $row->getRegisterRule(16)->value);
    }

    public function testNop(): void
    {
        // DW_CFA_def_cfa 7,8 → DW_CFA_nop → should not change state
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x0c\x07\x08\x00", $cie, 0x1000);

        $last = $rows[array_key_last($rows)];
        $this->assertSame(8, $last->cfaRule->offset);
    }

    public function testSameValue(): void
    {
        // DW_CFA_same_value reg=6 → 0x08 0x06
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x08\x06", $cie, 0x1000);

        $last = $rows[array_key_last($rows)];
        $this->assertSame(RegisterRuleType::SameValue, $last->getRegisterRule(6)->type);
    }

    public function testRegister(): void
    {
        // DW_CFA_register reg=6 target=7 → 0x09 0x06 0x07
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x09\x06\x07", $cie, 0x1000);

        $last = $rows[array_key_last($rows)];
        $rule = $last->getRegisterRule(6);
        $this->assertSame(RegisterRuleType::Register, $rule->type);
        $this->assertSame(7, $rule->value);
    }

    public function testUndefined(): void
    {
        // DW_CFA_undefined reg=6 → 0x07 0x06
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x07\x06", $cie, 0x1000);
        $last = $rows[array_key_last($rows)];
        $this->assertSame(RegisterRuleType::Undefined, $last->getRegisterRule(6)->type);
    }

    public function testAdvanceLoc1(): void
    {
        // DW_CFA_def_cfa 7,8 → DW_CFA_advance_loc1(0x10) → DW_CFA_def_cfa_offset 16
        // 0x0c 0x07 0x08 0x02 0x10 0x0e 0x10
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x0c\x07\x08\x02\x10\x0e\x10", $cie, 0x1000);
        $this->assertGreaterThanOrEqual(2, count($rows));
        $this->assertSame(0x1010, $rows[1]->location);
    }

    public function testAdvanceLoc2(): void
    {
        // DW_CFA_def_cfa 7,8 → DW_CFA_advance_loc2(0x0100) → DW_CFA_def_cfa_offset 16
        // 0x0c 0x07 0x08 0x03 0x00 0x01 0x0e 0x10
        $cie = $this->makeCie();
        $rows = $this->decoder->execute(
            "\x0c\x07\x08\x03\x00\x01\x0e\x10",
            $cie,
            0x1000,
        );
        $this->assertGreaterThanOrEqual(2, count($rows));
        $this->assertSame(0x1100, $rows[1]->location);
    }

    public function testAdvanceLoc4(): void
    {
        // DW_CFA_def_cfa 7,8 → DW_CFA_advance_loc4(0x00010000) → DW_CFA_def_cfa_offset 16
        $cie = $this->makeCie();
        $rows = $this->decoder->execute(
            "\x0c\x07\x08\x04\x00\x00\x01\x00\x0e\x10",
            $cie,
            0x1000,
        );
        $this->assertGreaterThanOrEqual(2, count($rows));
        $this->assertSame(0x11000, $rows[1]->location);
    }

    public function testOffsetExtended(): void
    {
        // DW_CFA_offset_extended reg=16 offset=2 → 0x05 0x10 0x02
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x05\x10\x02", $cie, 0x1000);
        $last = $rows[array_key_last($rows)];
        $this->assertSame(-16, $last->getRegisterRule(16)->value); // 2 * -8
    }

    public function testOffsetExtendedSf(): void
    {
        // DW_CFA_offset_extended_sf reg=6 offset=-2 → 0x11 0x06 0x7e
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x11\x06\x7e", $cie, 0x1000);
        $last = $rows[array_key_last($rows)];
        $this->assertSame(16, $last->getRegisterRule(6)->value); // -2 * -8
    }

    public function testDefCfaSf(): void
    {
        // DW_CFA_def_cfa_sf reg=7 offset=-2 → 0x12 0x07 0x7e
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x12\x07\x7e", $cie, 0x1000);
        $last = $rows[array_key_last($rows)];
        $this->assertSame(7, $last->cfaRule->register);
        $this->assertSame(16, $last->cfaRule->offset); // -2 * -8
    }

    public function testDefCfaOffsetSf(): void
    {
        // DW_CFA_def_cfa 7,8 → DW_CFA_def_cfa_offset_sf -3 → 0x13 0x7d
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x0c\x07\x08\x13\x7d", $cie, 0x1000);
        $last = $rows[array_key_last($rows)];
        $this->assertSame(24, $last->cfaRule->offset); // -3 * -8
    }

    public function testValOffset(): void
    {
        // DW_CFA_val_offset reg=6 offset=1 → 0x14 0x06 0x01
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x14\x06\x01", $cie, 0x1000);
        $last = $rows[array_key_last($rows)];
        $rule = $last->getRegisterRule(6);
        $this->assertSame(RegisterRuleType::ValOffset, $rule->type);
        $this->assertSame(-8, $rule->value); // 1 * -8
    }

    public function testValOffsetSf(): void
    {
        // DW_CFA_val_offset_sf reg=6 offset=-1 → 0x15 0x06 0x7f
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x15\x06\x7f", $cie, 0x1000);
        $last = $rows[array_key_last($rows)];
        $rule = $last->getRegisterRule(6);
        $this->assertSame(RegisterRuleType::ValOffset, $rule->type);
        $this->assertSame(8, $rule->value); // -1 * -8
    }

    public function testExpression(): void
    {
        // DW_CFA_expression reg=6 len=2 bytes=0x70,0x00 → 0x10 0x06 0x02 0x70 0x00
        $cie = $this->makeCie();
        $rows = $this->decoder->execute(
            "\x10\x06\x02\x70\x00",
            $cie,
            0x1000,
        );
        $last = $rows[array_key_last($rows)];
        $rule = $last->getRegisterRule(6);
        $this->assertSame(RegisterRuleType::Expression, $rule->type);
        $this->assertSame("\x70\x00", $rule->expressionBytes);
    }

    public function testValExpression(): void
    {
        // DW_CFA_val_expression reg=6 len=1 bytes=0x35 → 0x16 0x06 0x01 0x35
        $cie = $this->makeCie();
        $rows = $this->decoder->execute(
            "\x16\x06\x01\x35",
            $cie,
            0x1000,
        );
        $last = $rows[array_key_last($rows)];
        $rule = $last->getRegisterRule(6);
        $this->assertSame(RegisterRuleType::ValExpression, $rule->type);
        $this->assertSame("\x35", $rule->expressionBytes);
    }

    public function testDefCfaExpression(): void
    {
        // DW_CFA_def_cfa_expression len=2 bytes=0x77,0x08 → 0x0f 0x02 0x77 0x08
        $cie = $this->makeCie();
        $rows = $this->decoder->execute(
            "\x0f\x02\x77\x08",
            $cie,
            0x1000,
        );
        $last = $rows[array_key_last($rows)];
        $this->assertSame(CfaRuleType::Expression, $last->cfaRule->type);
        $this->assertSame("\x77\x08", $last->cfaRule->expressionBytes);
    }

    public function testGnuArgsSize(): void
    {
        // DW_CFA_GNU_args_size 8 → 0x2e 0x08 (should be silently consumed)
        $cie = $this->makeCie();
        $rows = $this->decoder->execute(
            "\x0c\x07\x08\x2e\x08",
            $cie,
            0x1000,
        );
        $last = $rows[array_key_last($rows)];
        // Should not affect CFA
        $this->assertSame(8, $last->cfaRule->offset);
    }

    public function testGnuNegativeOffsetExtended(): void
    {
        // DW_CFA_GNU_negative_offset_extended reg=6 offset=2 → 0x2f 0x06 0x02
        $cie = $this->makeCie();
        $rows = $this->decoder->execute("\x2f\x06\x02", $cie, 0x1000);
        $last = $rows[array_key_last($rows)];
        $rule = $last->getRegisterRule(6);
        $this->assertSame(RegisterRuleType::Offset, $rule->type);
        $this->assertSame(16, $rule->value); // -(2 * -8)
    }

    public function testRestoreExtended(): void
    {
        // CIE with initial: DW_CFA_offset reg=6 offset=1 → 0x86 0x01
        $cie = new Cie(1, -8, 16, "\x86\x01", 'zR', 0, null, null, null);
        $initial = $this->decoder->buildInitialRow($cie);

        // FDE: DW_CFA_undefined 6 → DW_CFA_restore_extended 6
        // 0x07 0x06 0x06 0x06
        $rows = $this->decoder->execute(
            "\x07\x06\x06\x06",
            $cie,
            0x1000,
            $initial,
        );
        $last = $rows[array_key_last($rows)];
        // After restore_extended, reg 6 should be back to initial (offset -8)
        $rule = $last->getRegisterRule(6);
        $this->assertSame(RegisterRuleType::Offset, $rule->type);
        $this->assertSame(-8, $rule->value);
    }

    public function testUnsupportedOpcodeThrows(): void
    {
        $cie = $this->makeCie();
        $this->expectException(DwarfException::class);
        // 0x3f is not a valid extended opcode
        $this->decoder->execute("\x3f", $cie, 0x1000);
    }
}
