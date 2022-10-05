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

namespace PhpProfiler\Lib\PhpInternals\Opcodes;

/** @psalm-immutable */
final class OpcodeV81 implements Opcode
{
    public const ZEND_NOP = 0;
    public const ZEND_ADD = 1;
    public const ZEND_SUB = 2;
    public const ZEND_MUL = 3;
    public const ZEND_DIV = 4;
    public const ZEND_MOD = 5;
    public const ZEND_SL = 6;
    public const ZEND_SR = 7;
    public const ZEND_CONCAT = 8;
    public const ZEND_BW_OR = 9;
    public const ZEND_BW_AND = 10;
    public const ZEND_BW_XOR = 11;
    public const ZEND_POW = 12;
    public const ZEND_BW_NOT = 13;
    public const ZEND_BOOL_NOT = 14;
    public const ZEND_BOOL_XOR = 15;
    public const ZEND_IS_IDENTICAL = 16;
    public const ZEND_IS_NOT_IDENTICAL = 17;
    public const ZEND_IS_EQUAL = 18;
    public const ZEND_IS_NOT_EQUAL = 19;
    public const ZEND_IS_SMALLER = 20;
    public const ZEND_IS_SMALLER_OR_EQUAL = 21;
    public const ZEND_ASSIGN = 22;
    public const ZEND_ASSIGN_DIM = 23;
    public const ZEND_ASSIGN_OBJ = 24;
    public const ZEND_ASSIGN_STATIC_PROP = 25;
    public const ZEND_ASSIGN_OP = 26;
    public const ZEND_ASSIGN_DIM_OP = 27;
    public const ZEND_ASSIGN_OBJ_OP = 28;
    public const ZEND_ASSIGN_STATIC_PROP_OP = 29;
    public const ZEND_ASSIGN_REF = 30;
    public const ZEND_QM_ASSIGN = 31;
    public const ZEND_ASSIGN_OBJ_REF = 32;
    public const ZEND_ASSIGN_STATIC_PROP_REF = 33;
    public const ZEND_PRE_INC = 34;
    public const ZEND_PRE_DEC = 35;
    public const ZEND_POST_INC = 36;
    public const ZEND_POST_DEC = 37;
    public const ZEND_PRE_INC_STATIC_PROP = 38;
    public const ZEND_PRE_DEC_STATIC_PROP = 39;
    public const ZEND_POST_INC_STATIC_PROP = 40;
    public const ZEND_POST_DEC_STATIC_PROP = 41;
    public const ZEND_JMP = 42;
    public const ZEND_JMPZ = 43;
    public const ZEND_JMPNZ = 44;
    public const ZEND_JMPZNZ = 45;
    public const ZEND_JMPZ_EX = 46;
    public const ZEND_JMPNZ_EX = 47;
    public const ZEND_CASE = 48;
    public const ZEND_CHECK_VAR = 49;
    public const ZEND_SEND_VAR_NO_REF_EX = 50;
    public const ZEND_CAST = 51;
    public const ZEND_BOOL = 52;
    public const ZEND_FAST_CONCAT = 53;
    public const ZEND_ROPE_INIT = 54;
    public const ZEND_ROPE_ADD = 55;
    public const ZEND_ROPE_END = 56;
    public const ZEND_BEGIN_SILENCE = 57;
    public const ZEND_END_SILENCE = 58;
    public const ZEND_INIT_FCALL_BY_NAME = 59;
    public const ZEND_DO_FCALL = 60;
    public const ZEND_INIT_FCALL = 61;
    public const ZEND_RETURN = 62;
    public const ZEND_RECV = 63;
    public const ZEND_RECV_INIT = 64;
    public const ZEND_SEND_VAL = 65;
    public const ZEND_SEND_VAR_EX = 66;
    public const ZEND_SEND_REF = 67;
    public const ZEND_NEW = 68;
    public const ZEND_INIT_NS_FCALL_BY_NAME = 69;
    public const ZEND_FREE = 70;
    public const ZEND_INIT_ARRAY = 71;
    public const ZEND_ADD_ARRAY_ELEMENT = 72;
    public const ZEND_INCLUDE_OR_EVAL = 73;
    public const ZEND_UNSET_VAR = 74;
    public const ZEND_UNSET_DIM = 75;
    public const ZEND_UNSET_OBJ = 76;
    public const ZEND_FE_RESET_R = 77;
    public const ZEND_FE_FETCH_R = 78;
    public const ZEND_EXIT = 79;
    public const ZEND_FETCH_R = 80;
    public const ZEND_FETCH_DIM_R = 81;
    public const ZEND_FETCH_OBJ_R = 82;
    public const ZEND_FETCH_W = 83;
    public const ZEND_FETCH_DIM_W = 84;
    public const ZEND_FETCH_OBJ_W = 85;
    public const ZEND_FETCH_RW = 86;
    public const ZEND_FETCH_DIM_RW = 87;
    public const ZEND_FETCH_OBJ_RW = 88;
    public const ZEND_FETCH_IS = 89;
    public const ZEND_FETCH_DIM_IS = 90;
    public const ZEND_FETCH_OBJ_IS = 91;
    public const ZEND_FETCH_FUNC_ARG = 92;
    public const ZEND_FETCH_DIM_FUNC_ARG = 93;
    public const ZEND_FETCH_OBJ_FUNC_ARG = 94;
    public const ZEND_FETCH_UNSET = 95;
    public const ZEND_FETCH_DIM_UNSET = 96;
    public const ZEND_FETCH_OBJ_UNSET = 97;
    public const ZEND_FETCH_LIST_R = 98;
    public const ZEND_FETCH_CONSTANT = 99;
    public const ZEND_CHECK_FUNC_ARG = 100;
    public const ZEND_EXT_STMT = 101;
    public const ZEND_EXT_FCALL_BEGIN = 102;
    public const ZEND_EXT_FCALL_END = 103;
    public const ZEND_EXT_NOP = 104;
    public const ZEND_TICKS = 105;
    public const ZEND_SEND_VAR_NO_REF = 106;
    public const ZEND_CATCH = 107;
    public const ZEND_THROW = 108;
    public const ZEND_FETCH_CLASS = 109;
    public const ZEND_CLONE = 110;
    public const ZEND_RETURN_BY_REF = 111;
    public const ZEND_INIT_METHOD_CALL = 112;
    public const ZEND_INIT_STATIC_METHOD_CALL = 113;
    public const ZEND_ISSET_ISEMPTY_VAR = 114;
    public const ZEND_ISSET_ISEMPTY_DIM_OBJ = 115;
    public const ZEND_SEND_VAL_EX = 116;
    public const ZEND_SEND_VAR = 117;
    public const ZEND_INIT_USER_CALL = 118;
    public const ZEND_SEND_ARRAY = 119;
    public const ZEND_SEND_USER = 120;
    public const ZEND_STRLEN = 121;
    public const ZEND_DEFINED = 122;
    public const ZEND_TYPE_CHECK = 123;
    public const ZEND_VERIFY_RETURN_TYPE = 124;
    public const ZEND_FE_RESET_RW = 125;
    public const ZEND_FE_FETCH_RW = 126;
    public const ZEND_FE_FREE = 127;
    public const ZEND_INIT_DYNAMIC_CALL = 128;
    public const ZEND_DO_ICALL = 129;
    public const ZEND_DO_UCALL = 130;
    public const ZEND_DO_FCALL_BY_NAME = 131;
    public const ZEND_PRE_INC_OBJ = 132;
    public const ZEND_PRE_DEC_OBJ = 133;
    public const ZEND_POST_INC_OBJ = 134;
    public const ZEND_POST_DEC_OBJ = 135;
    public const ZEND_ECHO = 136;
    public const ZEND_OP_DATA = 137;
    public const ZEND_INSTANCEOF = 138;
    public const ZEND_GENERATOR_CREATE = 139;
    public const ZEND_MAKE_REF = 140;
    public const ZEND_DECLARE_FUNCTION = 141;
    public const ZEND_DECLARE_LAMBDA_FUNCTION = 142;
    public const ZEND_DECLARE_CONST = 143;
    public const ZEND_DECLARE_CLASS = 144;
    public const ZEND_DECLARE_CLASS_DELAYED = 145;
    public const ZEND_DECLARE_ANON_CLASS = 146;
    public const ZEND_ADD_ARRAY_UNPACK = 147;
    public const ZEND_ISSET_ISEMPTY_PROP_OBJ = 148;
    public const ZEND_HANDLE_EXCEPTION = 149;
    public const ZEND_USER_OPCODE = 150;
    public const ZEND_ASSERT_CHECK = 151;
    public const ZEND_JMP_SET = 152;
    public const ZEND_UNSET_CV = 153;
    public const ZEND_ISSET_ISEMPTY_CV = 154;
    public const ZEND_FETCH_LIST_W = 155;
    public const ZEND_SEPARATE = 156;
    public const ZEND_FETCH_CLASS_NAME = 157;
    public const ZEND_CALL_TRAMPOLINE = 158;
    public const ZEND_DISCARD_EXCEPTION = 159;
    public const ZEND_YIELD = 160;
    public const ZEND_GENERATOR_RETURN = 161;
    public const ZEND_FAST_CALL = 162;
    public const ZEND_FAST_RET = 163;
    public const ZEND_RECV_VARIADIC = 164;
    public const ZEND_SEND_UNPACK = 165;
    public const ZEND_YIELD_FROM = 166;
    public const ZEND_COPY_TMP = 167;
    public const ZEND_BIND_GLOBAL = 168;
    public const ZEND_COALESCE = 169;
    public const ZEND_SPACESHIP = 170;
    public const ZEND_FUNC_NUM_ARGS = 171;
    public const ZEND_FUNC_GET_ARGS = 172;
    public const ZEND_FETCH_STATIC_PROP_R = 173;
    public const ZEND_FETCH_STATIC_PROP_W = 174;
    public const ZEND_FETCH_STATIC_PROP_RW = 175;
    public const ZEND_FETCH_STATIC_PROP_IS = 176;
    public const ZEND_FETCH_STATIC_PROP_FUNC_ARG = 177;
    public const ZEND_FETCH_STATIC_PROP_UNSET = 178;
    public const ZEND_UNSET_STATIC_PROP = 179;
    public const ZEND_ISSET_ISEMPTY_STATIC_PROP = 180;
    public const ZEND_FETCH_CLASS_CONSTANT = 181;
    public const ZEND_BIND_LEXICAL = 182;
    public const ZEND_BIND_STATIC = 183;
    public const ZEND_FETCH_THIS = 184;
    public const ZEND_SEND_FUNC_ARG = 185;
    public const ZEND_ISSET_ISEMPTY_THIS = 186;
    public const ZEND_SWITCH_LONG = 187;
    public const ZEND_SWITCH_STRING = 188;
    public const ZEND_IN_ARRAY = 189;
    public const ZEND_COUNT = 190;
    public const ZEND_GET_CLASS = 191;
    public const ZEND_GET_CALLED_CLASS = 192;
    public const ZEND_GET_TYPE = 193;
    public const ZEND_ARRAY_KEY_EXISTS = 194;
    public const ZEND_MATCH = 195;
    public const ZEND_CASE_STRICT = 196;
    public const ZEND_MATCH_ERROR = 197;
    public const ZEND_JMP_NULL = 198;
    public const ZEND_CHECK_UNDEF_ARGS = 199;
    public const ZEND_FETCH_GLOBALS = 200;
    public const ZEND_VERIFY_NEVER_TYPE = 201;
    public const ZEND_CALLABLE_CONVERT = 202;

    public const OPCODES = [
        self::ZEND_NOP,
        self::ZEND_ADD,
        self::ZEND_SUB,
        self::ZEND_MUL,
        self::ZEND_DIV,
        self::ZEND_MOD,
        self::ZEND_SL,
        self::ZEND_SR,
        self::ZEND_CONCAT,
        self::ZEND_BW_OR,
        self::ZEND_BW_AND,
        self::ZEND_BW_XOR,
        self::ZEND_POW,
        self::ZEND_BW_NOT,
        self::ZEND_BOOL_NOT,
        self::ZEND_BOOL_XOR,
        self::ZEND_IS_IDENTICAL,
        self::ZEND_IS_NOT_IDENTICAL,
        self::ZEND_IS_EQUAL,
        self::ZEND_IS_NOT_EQUAL,
        self::ZEND_IS_SMALLER,
        self::ZEND_IS_SMALLER_OR_EQUAL,
        self::ZEND_ASSIGN,
        self::ZEND_ASSIGN_DIM,
        self::ZEND_ASSIGN_OBJ,
        self::ZEND_ASSIGN_STATIC_PROP,
        self::ZEND_ASSIGN_OP,
        self::ZEND_ASSIGN_DIM_OP,
        self::ZEND_ASSIGN_OBJ_OP,
        self::ZEND_ASSIGN_STATIC_PROP_OP,
        self::ZEND_ASSIGN_REF,
        self::ZEND_QM_ASSIGN,
        self::ZEND_ASSIGN_OBJ_REF,
        self::ZEND_ASSIGN_STATIC_PROP_REF,
        self::ZEND_PRE_INC,
        self::ZEND_PRE_DEC,
        self::ZEND_POST_INC,
        self::ZEND_POST_DEC,
        self::ZEND_PRE_INC_STATIC_PROP,
        self::ZEND_PRE_DEC_STATIC_PROP,
        self::ZEND_POST_INC_STATIC_PROP,
        self::ZEND_POST_DEC_STATIC_PROP,
        self::ZEND_JMP,
        self::ZEND_JMPZ,
        self::ZEND_JMPNZ,
        self::ZEND_JMPZNZ,
        self::ZEND_JMPZ_EX,
        self::ZEND_JMPNZ_EX,
        self::ZEND_CASE,
        self::ZEND_CHECK_VAR,
        self::ZEND_SEND_VAR_NO_REF_EX,
        self::ZEND_CAST,
        self::ZEND_BOOL,
        self::ZEND_FAST_CONCAT,
        self::ZEND_ROPE_INIT,
        self::ZEND_ROPE_ADD,
        self::ZEND_ROPE_END,
        self::ZEND_BEGIN_SILENCE,
        self::ZEND_END_SILENCE,
        self::ZEND_INIT_FCALL_BY_NAME,
        self::ZEND_DO_FCALL,
        self::ZEND_INIT_FCALL,
        self::ZEND_RETURN,
        self::ZEND_RECV,
        self::ZEND_RECV_INIT,
        self::ZEND_SEND_VAL,
        self::ZEND_SEND_VAR_EX,
        self::ZEND_SEND_REF,
        self::ZEND_NEW,
        self::ZEND_INIT_NS_FCALL_BY_NAME,
        self::ZEND_FREE,
        self::ZEND_INIT_ARRAY,
        self::ZEND_ADD_ARRAY_ELEMENT,
        self::ZEND_INCLUDE_OR_EVAL,
        self::ZEND_UNSET_VAR,
        self::ZEND_UNSET_DIM,
        self::ZEND_UNSET_OBJ,
        self::ZEND_FE_RESET_R,
        self::ZEND_FE_FETCH_R,
        self::ZEND_EXIT,
        self::ZEND_FETCH_R,
        self::ZEND_FETCH_DIM_R,
        self::ZEND_FETCH_OBJ_R,
        self::ZEND_FETCH_W,
        self::ZEND_FETCH_DIM_W,
        self::ZEND_FETCH_OBJ_W,
        self::ZEND_FETCH_RW,
        self::ZEND_FETCH_DIM_RW,
        self::ZEND_FETCH_OBJ_RW,
        self::ZEND_FETCH_IS,
        self::ZEND_FETCH_DIM_IS,
        self::ZEND_FETCH_OBJ_IS,
        self::ZEND_FETCH_FUNC_ARG,
        self::ZEND_FETCH_DIM_FUNC_ARG,
        self::ZEND_FETCH_OBJ_FUNC_ARG,
        self::ZEND_FETCH_UNSET,
        self::ZEND_FETCH_DIM_UNSET,
        self::ZEND_FETCH_OBJ_UNSET,
        self::ZEND_FETCH_LIST_R,
        self::ZEND_FETCH_CONSTANT,
        self::ZEND_CHECK_FUNC_ARG,
        self::ZEND_EXT_STMT,
        self::ZEND_EXT_FCALL_BEGIN,
        self::ZEND_EXT_FCALL_END,
        self::ZEND_EXT_NOP,
        self::ZEND_TICKS,
        self::ZEND_SEND_VAR_NO_REF,
        self::ZEND_CATCH,
        self::ZEND_THROW,
        self::ZEND_FETCH_CLASS,
        self::ZEND_CLONE,
        self::ZEND_RETURN_BY_REF,
        self::ZEND_INIT_METHOD_CALL,
        self::ZEND_INIT_STATIC_METHOD_CALL,
        self::ZEND_ISSET_ISEMPTY_VAR,
        self::ZEND_ISSET_ISEMPTY_DIM_OBJ,
        self::ZEND_SEND_VAL_EX,
        self::ZEND_SEND_VAR,
        self::ZEND_INIT_USER_CALL,
        self::ZEND_SEND_ARRAY,
        self::ZEND_SEND_USER,
        self::ZEND_STRLEN,
        self::ZEND_DEFINED,
        self::ZEND_TYPE_CHECK,
        self::ZEND_VERIFY_RETURN_TYPE,
        self::ZEND_FE_RESET_RW,
        self::ZEND_FE_FETCH_RW,
        self::ZEND_FE_FREE,
        self::ZEND_INIT_DYNAMIC_CALL,
        self::ZEND_DO_ICALL,
        self::ZEND_DO_UCALL,
        self::ZEND_DO_FCALL_BY_NAME,
        self::ZEND_PRE_INC_OBJ,
        self::ZEND_PRE_DEC_OBJ,
        self::ZEND_POST_INC_OBJ,
        self::ZEND_POST_DEC_OBJ,
        self::ZEND_ECHO,
        self::ZEND_OP_DATA,
        self::ZEND_INSTANCEOF,
        self::ZEND_GENERATOR_CREATE,
        self::ZEND_MAKE_REF,
        self::ZEND_DECLARE_FUNCTION,
        self::ZEND_DECLARE_LAMBDA_FUNCTION,
        self::ZEND_DECLARE_CONST,
        self::ZEND_DECLARE_CLASS,
        self::ZEND_DECLARE_CLASS_DELAYED,
        self::ZEND_DECLARE_ANON_CLASS,
        self::ZEND_ADD_ARRAY_UNPACK,
        self::ZEND_ISSET_ISEMPTY_PROP_OBJ,
        self::ZEND_HANDLE_EXCEPTION,
        self::ZEND_USER_OPCODE,
        self::ZEND_ASSERT_CHECK,
        self::ZEND_JMP_SET,
        self::ZEND_UNSET_CV,
        self::ZEND_ISSET_ISEMPTY_CV,
        self::ZEND_FETCH_LIST_W,
        self::ZEND_SEPARATE,
        self::ZEND_FETCH_CLASS_NAME,
        self::ZEND_CALL_TRAMPOLINE,
        self::ZEND_DISCARD_EXCEPTION,
        self::ZEND_YIELD,
        self::ZEND_GENERATOR_RETURN,
        self::ZEND_FAST_CALL,
        self::ZEND_FAST_RET,
        self::ZEND_RECV_VARIADIC,
        self::ZEND_SEND_UNPACK,
        self::ZEND_YIELD_FROM,
        self::ZEND_COPY_TMP,
        self::ZEND_BIND_GLOBAL,
        self::ZEND_COALESCE,
        self::ZEND_SPACESHIP,
        self::ZEND_FUNC_NUM_ARGS,
        self::ZEND_FUNC_GET_ARGS,
        self::ZEND_FETCH_STATIC_PROP_R,
        self::ZEND_FETCH_STATIC_PROP_W,
        self::ZEND_FETCH_STATIC_PROP_RW,
        self::ZEND_FETCH_STATIC_PROP_IS,
        self::ZEND_FETCH_STATIC_PROP_FUNC_ARG,
        self::ZEND_FETCH_STATIC_PROP_UNSET,
        self::ZEND_UNSET_STATIC_PROP,
        self::ZEND_ISSET_ISEMPTY_STATIC_PROP,
        self::ZEND_FETCH_CLASS_CONSTANT,
        self::ZEND_BIND_LEXICAL,
        self::ZEND_BIND_STATIC,
        self::ZEND_FETCH_THIS,
        self::ZEND_SEND_FUNC_ARG,
        self::ZEND_ISSET_ISEMPTY_THIS,
        self::ZEND_SWITCH_LONG,
        self::ZEND_SWITCH_STRING,
        self::ZEND_IN_ARRAY,
        self::ZEND_COUNT,
        self::ZEND_GET_CLASS,
        self::ZEND_GET_CALLED_CLASS,
        self::ZEND_GET_TYPE,
        self::ZEND_ARRAY_KEY_EXISTS,
        self::ZEND_MATCH,
        self::ZEND_CASE_STRICT,
        self::ZEND_MATCH_ERROR,
        self::ZEND_JMP_NULL,
        self::ZEND_CHECK_UNDEF_ARGS,
        self::ZEND_FETCH_GLOBALS,
        self::ZEND_VERIFY_NEVER_TYPE,
        self::ZEND_CALLABLE_CONVERT,
    ];

    private const OPCODE_NAMES = [
        'ZEND_NOP',
        'ZEND_ADD',
        'ZEND_SUB',
        'ZEND_MUL',
        'ZEND_DIV',
        'ZEND_MOD',
        'ZEND_SL',
        'ZEND_SR',
        'ZEND_CONCAT',
        'ZEND_BW_OR',
        'ZEND_BW_AND',
        'ZEND_BW_XOR',
        'ZEND_POW',
        'ZEND_BW_NOT',
        'ZEND_BOOL_NOT',
        'ZEND_BOOL_XOR',
        'ZEND_IS_IDENTICAL',
        'ZEND_IS_NOT_IDENTICAL',
        'ZEND_IS_EQUAL',
        'ZEND_IS_NOT_EQUAL',
        'ZEND_IS_SMALLER',
        'ZEND_IS_SMALLER_OR_EQUAL',
        'ZEND_ASSIGN',
        'ZEND_ASSIGN_DIM',
        'ZEND_ASSIGN_OBJ',
        'ZEND_ASSIGN_STATIC_PROP',
        'ZEND_ASSIGN_OP',
        'ZEND_ASSIGN_DIM_OP',
        'ZEND_ASSIGN_OBJ_OP',
        'ZEND_ASSIGN_STATIC_PROP_OP',
        'ZEND_ASSIGN_REF',
        'ZEND_QM_ASSIGN',
        'ZEND_ASSIGN_OBJ_REF',
        'ZEND_ASSIGN_STATIC_PROP_REF',
        'ZEND_PRE_INC',
        'ZEND_PRE_DEC',
        'ZEND_POST_INC',
        'ZEND_POST_DEC',
        'ZEND_PRE_INC_STATIC_PROP',
        'ZEND_PRE_DEC_STATIC_PROP',
        'ZEND_POST_INC_STATIC_PROP',
        'ZEND_POST_DEC_STATIC_PROP',
        'ZEND_JMP',
        'ZEND_JMPZ',
        'ZEND_JMPNZ',
        'ZEND_JMPZNZ',
        'ZEND_JMPZ_EX',
        'ZEND_JMPNZ_EX',
        'ZEND_CASE',
        'ZEND_CHECK_VAR',
        'ZEND_SEND_VAR_NO_REF_EX',
        'ZEND_CAST',
        'ZEND_BOOL',
        'ZEND_FAST_CONCAT',
        'ZEND_ROPE_INIT',
        'ZEND_ROPE_ADD',
        'ZEND_ROPE_END',
        'ZEND_BEGIN_SILENCE',
        'ZEND_END_SILENCE',
        'ZEND_INIT_FCALL_BY_NAME',
        'ZEND_DO_FCALL',
        'ZEND_INIT_FCALL',
        'ZEND_RETURN',
        'ZEND_RECV',
        'ZEND_RECV_INIT',
        'ZEND_SEND_VAL',
        'ZEND_SEND_VAR_EX',
        'ZEND_SEND_REF',
        'ZEND_NEW',
        'ZEND_INIT_NS_FCALL_BY_NAME',
        'ZEND_FREE',
        'ZEND_INIT_ARRAY',
        'ZEND_ADD_ARRAY_ELEMENT',
        'ZEND_INCLUDE_OR_EVAL',
        'ZEND_UNSET_VAR',
        'ZEND_UNSET_DIM',
        'ZEND_UNSET_OBJ',
        'ZEND_FE_RESET_R',
        'ZEND_FE_FETCH_R',
        'ZEND_EXIT',
        'ZEND_FETCH_R',
        'ZEND_FETCH_DIM_R',
        'ZEND_FETCH_OBJ_R',
        'ZEND_FETCH_W',
        'ZEND_FETCH_DIM_W',
        'ZEND_FETCH_OBJ_W',
        'ZEND_FETCH_RW',
        'ZEND_FETCH_DIM_RW',
        'ZEND_FETCH_OBJ_RW',
        'ZEND_FETCH_IS',
        'ZEND_FETCH_DIM_IS',
        'ZEND_FETCH_OBJ_IS',
        'ZEND_FETCH_FUNC_ARG',
        'ZEND_FETCH_DIM_FUNC_ARG',
        'ZEND_FETCH_OBJ_FUNC_ARG',
        'ZEND_FETCH_UNSET',
        'ZEND_FETCH_DIM_UNSET',
        'ZEND_FETCH_OBJ_UNSET',
        'ZEND_FETCH_LIST_R',
        'ZEND_FETCH_CONSTANT',
        'ZEND_CHECK_FUNC_ARG',
        'ZEND_EXT_STMT',
        'ZEND_EXT_FCALL_BEGIN',
        'ZEND_EXT_FCALL_END',
        'ZEND_EXT_NOP',
        'ZEND_TICKS',
        'ZEND_SEND_VAR_NO_REF',
        'ZEND_CATCH',
        'ZEND_THROW',
        'ZEND_FETCH_CLASS',
        'ZEND_CLONE',
        'ZEND_RETURN_BY_REF',
        'ZEND_INIT_METHOD_CALL',
        'ZEND_INIT_STATIC_METHOD_CALL',
        'ZEND_ISSET_ISEMPTY_VAR',
        'ZEND_ISSET_ISEMPTY_DIM_OBJ',
        'ZEND_SEND_VAL_EX',
        'ZEND_SEND_VAR',
        'ZEND_INIT_USER_CALL',
        'ZEND_SEND_ARRAY',
        'ZEND_SEND_USER',
        'ZEND_STRLEN',
        'ZEND_DEFINED',
        'ZEND_TYPE_CHECK',
        'ZEND_VERIFY_RETURN_TYPE',
        'ZEND_FE_RESET_RW',
        'ZEND_FE_FETCH_RW',
        'ZEND_FE_FREE',
        'ZEND_INIT_DYNAMIC_CALL',
        'ZEND_DO_ICALL',
        'ZEND_DO_UCALL',
        'ZEND_DO_FCALL_BY_NAME',
        'ZEND_PRE_INC_OBJ',
        'ZEND_PRE_DEC_OBJ',
        'ZEND_POST_INC_OBJ',
        'ZEND_POST_DEC_OBJ',
        'ZEND_ECHO',
        'ZEND_OP_DATA',
        'ZEND_INSTANCEOF',
        'ZEND_GENERATOR_CREATE',
        'ZEND_MAKE_REF',
        'ZEND_DECLARE_FUNCTION',
        'ZEND_DECLARE_LAMBDA_FUNCTION',
        'ZEND_DECLARE_CONST',
        'ZEND_DECLARE_CLASS',
        'ZEND_DECLARE_CLASS_DELAYED',
        'ZEND_DECLARE_ANON_CLASS',
        'ZEND_ADD_ARRAY_UNPACK',
        'ZEND_ISSET_ISEMPTY_PROP_OBJ',
        'ZEND_HANDLE_EXCEPTION',
        'ZEND_USER_OPCODE',
        'ZEND_ASSERT_CHECK',
        'ZEND_JMP_SET',
        'ZEND_UNSET_CV',
        'ZEND_ISSET_ISEMPTY_CV',
        'ZEND_FETCH_LIST_W',
        'ZEND_SEPARATE',
        'ZEND_FETCH_CLASS_NAME',
        'ZEND_CALL_TRAMPOLINE',
        'ZEND_DISCARD_EXCEPTION',
        'ZEND_YIELD',
        'ZEND_GENERATOR_RETURN',
        'ZEND_FAST_CALL',
        'ZEND_FAST_RET',
        'ZEND_RECV_VARIADIC',
        'ZEND_SEND_UNPACK',
        'ZEND_YIELD_FROM',
        'ZEND_COPY_TMP',
        'ZEND_BIND_GLOBAL',
        'ZEND_COALESCE',
        'ZEND_SPACESHIP',
        'ZEND_FUNC_NUM_ARGS',
        'ZEND_FUNC_GET_ARGS',
        'ZEND_FETCH_STATIC_PROP_R',
        'ZEND_FETCH_STATIC_PROP_W',
        'ZEND_FETCH_STATIC_PROP_RW',
        'ZEND_FETCH_STATIC_PROP_IS',
        'ZEND_FETCH_STATIC_PROP_FUNC_ARG',
        'ZEND_FETCH_STATIC_PROP_UNSET',
        'ZEND_UNSET_STATIC_PROP',
        'ZEND_ISSET_ISEMPTY_STATIC_PROP',
        'ZEND_FETCH_CLASS_CONSTANT',
        'ZEND_BIND_LEXICAL',
        'ZEND_BIND_STATIC',
        'ZEND_FETCH_THIS',
        'ZEND_SEND_FUNC_ARG',
        'ZEND_ISSET_ISEMPTY_THIS',
        'ZEND_SWITCH_LONG',
        'ZEND_SWITCH_STRING',
        'ZEND_IN_ARRAY',
        'ZEND_COUNT',
        'ZEND_GET_CLASS',
        'ZEND_GET_CALLED_CLASS',
        'ZEND_GET_TYPE',
        'ZEND_ARRAY_KEY_EXISTS',
        'ZEND_MATCH',
        'ZEND_CASE_STRICT',
        'ZEND_MATCH_ERROR',
        'ZEND_JMP_NULL',
        'ZEND_CHECK_UNDEF_ARGS',
        'ZEND_FETCH_GLOBALS',
        'ZEND_VERIFY_NEVER_TYPE',
        'ZEND_CALLABLE_CONVERT',
    ];

    public function __construct(
        private int $opcode
    ) {
    }

    public function getName(): string
    {
        return self::OPCODE_NAMES[$this->opcode] ?? '';
    }
}
