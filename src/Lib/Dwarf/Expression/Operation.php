<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Dwarf\Expression;

use PhpProfiler\Lib\Dwarf\Expression\Opcodes\LiteralEncodings\Addr;
use PhpProfiler\Lib\Dwarf\Expression\Opcodes\LiteralEncodings\AddrConstX;
use PhpProfiler\Lib\Dwarf\Expression\Opcodes\LiteralEncodings\Constant;
use PhpProfiler\Lib\Dwarf\Expression\Opcodes\LiteralEncodings\Lit;
use PhpProfiler\Lib\Dwarf\Expression\Opcodes\Opcode;

final class Operation
{
    // phpcs:disable Generic.NamingConventions.UpperCaseConstantName
    public const DW_OP_addr = 0x03; // 1 operands, constant address(size is target specific)
    public const DW_OP_deref = 0x06; // 0 operands
    public const DW_OP_const1u = 0x08; // 1 operands, 1-byte constant
    public const DW_OP_const1s = 0x09; // 1 operands, 1-byte constant
    public const DW_OP_const2u = 0x0a; // 1 operands, 2-byte constant
    public const DW_OP_const2s = 0x0b; // 1 operands, 2-byte constant
    public const DW_OP_const4u = 0x0c; // 1 operands, 4-byte constant
    public const DW_OP_const4s = 0x0d; // 1 operands, 4-byte constant
    public const DW_OP_const8u = 0x0e; // 1 operands, 8-byte constant
    public const DW_OP_const8s = 0x0f; // 1 operands, 8-byte constant
    public const DW_OP_constu = 0x10; // 1 operands, ULEBB128 constant
    public const DW_OP_consts = 0x11; // 1 operands, SLEBB128 constant
    public const DW_OP_dup = 0x12; // 0 operands
    public const DW_OP_drop = 0x13; // 0 operands
    public const DW_OP_over = 0x14; // 0 operands
    public const DW_OP_pick = 0x15; // 1 operands, 1-byte stack index
    public const DW_OP_swap = 0x16; // 0 operands
    public const DW_OP_rot = 0x17; // 0 operands
    public const DW_OP_xderef = 0x18; // 0 operands
    public const DW_OP_abs = 0x19; // 0 operands
    public const DW_OP_and = 0x1a; // 0 operands
    public const DW_OP_div = 0x1b; // 0 operands
    public const DW_OP_minus = 0x1c; // 0 operands
    public const DW_OP_mod = 0x1d; // 0 operands
    public const DW_OP_mul = 0x1e; // 0 operands
    public const DW_OP_neg = 0x1f; // 0 operands
    public const DW_OP_not = 0x20; // 0 operands
    public const DW_OP_or = 0x21; // 0 operands
    public const DW_OP_plus = 0x22; // 0 operands
    public const DW_OP_plus_uconst = 0x23; // 1 operands, ULEBB128 addend
    public const DW_OP_shl = 0x24; // 0 operands
    public const DW_OP_shr = 0x25; // 0 operands
    public const DW_OP_shra = 0x26; // 0 operands
    public const DW_OP_xor = 0x27; // 0 operands
    public const DW_OP_bra = 0x28; // 1 operands, signed  2-byte constant
    public const DW_OP_eq = 0x29; // 0 operands
    public const DW_OP_ge = 0x2a; // 0 operands
    public const DW_OP_gt = 0x2b; // 0 operands
    public const DW_OP_le = 0x2c; // 0 operands
    public const DW_OP_lt = 0x2d; // 0 operands
    public const DW_OP_ne = 0x2e; // 0 operands
    public const DW_OP_skip = 0x2f; // 1 operands, signed  2-byte constant
    public const DW_OP_lit0 = 0x30; // 0 operands
    public const DW_OP_lit1 = 0x31; // 0 operands
    public const DW_OP_lit2 = 0x32; // 0 operands
    public const DW_OP_lit3 = 0x33; // 0 operands
    public const DW_OP_lit4 = 0x34; // 0 operands
    public const DW_OP_lit5 = 0x35; // 0 operands
    public const DW_OP_lit6 = 0x36; // 0 operands
    public const DW_OP_lit7 = 0x37; // 0 operands
    public const DW_OP_lit8 = 0x38; // 0 operands
    public const DW_OP_lit9 = 0x39; // 0 operands
    public const DW_OP_lit10 = 0x3a; // 0 operands
    public const DW_OP_lit11 = 0x3b; // 0 operands
    public const DW_OP_lit12 = 0x3c; // 0 operands
    public const DW_OP_lit13 = 0x3d; // 0 operands
    public const DW_OP_lit14 = 0x3e; // 0 operands
    public const DW_OP_lit15 = 0x3f; // 0 operands
    public const DW_OP_lit16 = 0x40; // 0 operands
    public const DW_OP_lit17 = 0x41; // 0 operands
    public const DW_OP_lit18 = 0x42; // 0 operands
    public const DW_OP_lit19 = 0x43; // 0 operands
    public const DW_OP_lit20 = 0x44; // 0 operands
    public const DW_OP_lit21 = 0x45; // 0 operands
    public const DW_OP_lit22 = 0x46; // 0 operands
    public const DW_OP_lit23 = 0x47; // 0 operands
    public const DW_OP_lit24 = 0x48; // 0 operands
    public const DW_OP_lit25 = 0x49; // 0 operands
    public const DW_OP_lit26 = 0x4a; // 0 operands
    public const DW_OP_lit27 = 0x4b; // 0 operands
    public const DW_OP_lit28 = 0x4c; // 0 operands
    public const DW_OP_lit29 = 0x4d; // 0 operands
    public const DW_OP_lit30 = 0x4e; // 0 operands
    public const DW_OP_lit31 = 0x4f; // 0 operands
    public const DW_OP_reg0 = 0x50; // 0 operands
    public const DW_OP_reg1 = 0x51; // 0 operands
    public const DW_OP_reg2 = 0x52; // 0 operands
    public const DW_OP_reg3 = 0x53; // 0 operands
    public const DW_OP_reg4 = 0x54; // 0 operands
    public const DW_OP_reg5 = 0x55; // 0 operands
    public const DW_OP_reg6 = 0x56; // 0 operands
    public const DW_OP_reg7 = 0x57; // 0 operands
    public const DW_OP_reg8 = 0x58; // 0 operands
    public const DW_OP_reg9 = 0x59; // 0 operands
    public const DW_OP_reg10 = 0x5a; // 0 operands
    public const DW_OP_reg11 = 0x5b; // 0 operands
    public const DW_OP_reg12 = 0x5c; // 0 operands
    public const DW_OP_reg13 = 0x5d; // 0 operands
    public const DW_OP_reg14 = 0x5e; // 0 operands
    public const DW_OP_reg15 = 0x5f; // 0 operands
    public const DW_OP_reg16 = 0x60; // 0 operands
    public const DW_OP_reg17 = 0x61; // 0 operands
    public const DW_OP_reg18 = 0x62; // 0 operands
    public const DW_OP_reg19 = 0x63; // 0 operands
    public const DW_OP_reg20 = 0x64; // 0 operands
    public const DW_OP_reg21 = 0x65; // 0 operands
    public const DW_OP_reg22 = 0x66; // 0 operands
    public const DW_OP_reg23 = 0x67; // 0 operands
    public const DW_OP_reg24 = 0x68; // 0 operands
    public const DW_OP_reg25 = 0x69; // 0 operands
    public const DW_OP_reg26 = 0x6a; // 0 operands
    public const DW_OP_reg27 = 0x6b; // 0 operands
    public const DW_OP_reg28 = 0x6c; // 0 operands
    public const DW_OP_reg29 = 0x6d; // 0 operands
    public const DW_OP_reg30 = 0x6e; // 0 operands
    public const DW_OP_reg31 = 0x6f; // 0 operands
    public const DW_OP_breg0 = 0x70; // 1 operands, SLEB128 offset
    public const DW_OP_breg1 = 0x71; // 1 operands, base register
    public const DW_OP_breg2 = 0x72; // 1 operands, base register
    public const DW_OP_breg3 = 0x73; // 1 operands, base register
    public const DW_OP_breg4 = 0x74; // 1 operands, base register
    public const DW_OP_breg5 = 0x75; // 1 operands, base register
    public const DW_OP_breg6 = 0x76; // 1 operands, base register
    public const DW_OP_breg7 = 0x77; // 1 operands, base register
    public const DW_OP_breg8 = 0x78; // 1 operands, base register
    public const DW_OP_breg9 = 0x79; // 1 operands, base register
    public const DW_OP_breg10 = 0x7a; // 1 operands, base register
    public const DW_OP_breg11 = 0x7b; // 1 operands, base register
    public const DW_OP_breg12 = 0x7c; // 1 operands, base register
    public const DW_OP_breg13 = 0x7d; // 1 operands, base register
    public const DW_OP_breg14 = 0x7e; // 1 operands, base register
    public const DW_OP_breg15 = 0x7f; // 1 operands, base register
    public const DW_OP_breg16 = 0x80; // 1 operands, base register
    public const DW_OP_breg17 = 0x81; // 1 operands, base register
    public const DW_OP_breg18 = 0x82; // 1 operands, base register
    public const DW_OP_breg19 = 0x83; // 1 operands, base register
    public const DW_OP_breg20 = 0x84; // 1 operands, base register
    public const DW_OP_breg21 = 0x85; // 1 operands, base register
    public const DW_OP_breg22 = 0x86; // 1 operands, base register
    public const DW_OP_breg23 = 0x87; // 1 operands, base register
    public const DW_OP_breg24 = 0x88; // 1 operands, base register
    public const DW_OP_breg25 = 0x89; // 1 operands, base register
    public const DW_OP_breg26 = 0x8a; // 1 operands, base register
    public const DW_OP_breg27 = 0x8b; // 1 operands, base register
    public const DW_OP_breg28 = 0x8c; // 1 operands, base register
    public const DW_OP_breg29 = 0x8d; // 1 operands, base register
    public const DW_OP_breg30 = 0x8e; // 1 operands, base register
    public const DW_OP_breg31 = 0x8f; // 1 operands
    public const DW_OP_regx = 0x90; // 1 operands, ULEB128 register
    public const DW_OP_fbreg = 0x91; // 1 operands, SLEB128 offset
    public const DW_OP_bregx = 0x92; // 2 operands, ULEB128 register,SLEB128 offset
    public const DW_OP_piece = 0x93; // 1 operands, ULEB128 size of piece
    public const DW_OP_deref_size = 0x94; // 1 operands, 1-byte size of data retrieved
    public const DW_OP_xderef_size = 0x95; // 1 operands, 1-byte size of data retrieved
    public const DW_OP_nop = 0x96; // 0 operands
    public const DW_OP_push_object_address = 0x97; // 0 operands
    public const DW_OP_call2 = 0x98; // 1 operands, 2-byte offset of DIE
    public const DW_OP_call4 = 0x99; // 1 operands, 4-byte offset of DIE
    public const DW_OP_call_ref = 0x9a; // 1 operands, 4- or 8-byte offset of DIE
    public const DW_OP_form_tls_address = 0x9b; // 0 operands
    public const DW_OP_call_frame_cfa = 0x9c; // 0 operands
    public const DW_OP_bit_piece = 0x9d; // 2 operands, ULEB128 size,ULEB128 offset
    public const DW_OP_implicit_value = 0x9e; // 2 operands, ULEB128 size,block of that size
    public const DW_OP_stack_value = 0x9f; // 0 operands
    public const DW_OP_implicit_pointer = 0xa0; // 2 operands, 4- or 8-byte offset of DIE,SLEB128 constant offset
    public const DW_OP_addrx = 0xa1; // 1 operands, ULEB128 indirect address
    public const DW_OP_constx = 0xa2; // 1 operands, ULEB128 indirect constant
    public const DW_OP_entry_value = 0xa3; // 2 operands, ULEB128 size,block of that size
    public const DW_OP_const_type = 0xa4; // 3 operands, ULEB128 type entry offset,1-byte size,constant value
    public const DW_OP_regval_type = 0xa5; // 2 operands, ULEB128 register number,ULEB128 constant offset
    public const DW_OP_deref_type = 0xa6; // 2 operands, 1-byte size,ULEB128 type entry offset
    public const DW_OP_xderef_type = 0xa7; // 2 operands, 1-byte size,ULEB128 type entry offset
    public const DW_OP_convert = 0xa8; // 1 operands, ULEB128 type entry offset
    public const DW_OP_reinterpret = 0xa9; // 1 operands, ULEB128 type entry offset
    public const DW_OP_lo_user = 0xe0;
    public const DW_OP_hi_user = 0xff;
    // phpcs:enable Generic.NamingConventions.UpperCaseConstantName

    private array $operands;

    public function __construct(
        private int $opcode,
        ...$operands
    ) {
        $this->operands = $operands;
    }

    public function getOpcode(ExpressionContext $expression_context): Opcode
    {
        return match ($this->opcode) {
            self::DW_OP_addr => new Addr(),
            self::DW_OP_lit0, self::DW_OP_lit1, self::DW_OP_lit2,
            self::DW_OP_lit3, self::DW_OP_lit4, self::DW_OP_lit5,
            self::DW_OP_lit6, self::DW_OP_lit7, self::DW_OP_lit8,
            self::DW_OP_lit9, self::DW_OP_lit10, self::DW_OP_lit11,
            self::DW_OP_lit12, self::DW_OP_lit13, self::DW_OP_lit14,
            self::DW_OP_lit15, self::DW_OP_lit16, self::DW_OP_lit17,
            self::DW_OP_lit18, self::DW_OP_lit19, self::DW_OP_lit20,
            self::DW_OP_lit21, self::DW_OP_lit22, self::DW_OP_lit23,
            self::DW_OP_lit24, self::DW_OP_lit25, self::DW_OP_lit26,
            self::DW_OP_lit27, self::DW_OP_lit28, self::DW_OP_lit29,
            self::DW_OP_lit30, self::DW_OP_lit31
                => new Lit($this->opcode - self::DW_OP_lit0),
            self::DW_OP_const1s, self::DW_OP_const1u,
            self::DW_OP_const2s, self::DW_OP_const2u,
            self::DW_OP_const4s, self::DW_OP_const4u,
            self::DW_OP_const8s, self::DW_OP_const8u,
            self::DW_OP_consts, self::DW_OP_constu
                => new Constant(),
            self::DW_OP_constx, self::DW_OP_addrx
                => new AddrConstX($expression_context),
        };
    }

    public function getOperands(): array
    {
        return $this->operands;
    }
}
