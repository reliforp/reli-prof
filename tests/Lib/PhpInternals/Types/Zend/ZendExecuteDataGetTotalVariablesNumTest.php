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

namespace Reli\Lib\PhpInternals\Types\Zend;

use FFI;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Covers {@see ZendExecuteData::getTotalVariablesNum()}.
 *
 * The historical formula `last_var + T + (real_arg_num - arg_num)`
 * went negative whenever a call site omitted optional arguments — a
 * function declared `f($a, $b = null)` called as `f(1)` would report
 * `last_var + T - 1`, undercounting the variable table size by one
 * zval for every missing declared parameter. PHP's own
 * `zend_vm_calc_used_stack` clamps the extra term via
 * `MIN(arg_num, num_args)`, so the corrected formula here uses
 * `max(0, real_arg_num - arg_num)`. The cases below pin that
 * behaviour:
 *
 *   - normal call (real == declared)
 *   - call passing MORE args than declared (e.g. variadic)
 *   - call passing FEWER args than declared (optional args omitted)
 *   - internal function (formula bypassed; returns This.u2.num_args)
 *   - null `func` (returns 0)
 */
class ZendExecuteDataGetTotalVariablesNumTest extends TestCase
{
    private const PHP_VERSION = ZendTypeReader::V84;
    /** Pretend address for the synthetic function pointer. */
    private const FAKE_FUNC_PTR_ADDR = 0x10000;

    /**
     * Build a `zend_execute_data` whose `func` points at our synthetic
     * address and whose `This.u2.num_args` equals the given count. The
     * returned object is paired with the dereferencer that resolves
     * `func` to a synthetic ZendFunction with the requested op_array
     * fields.
     *
     * @return array{ZendExecuteData, Dereferencer}
     */
    private function makePair(
        ZendTypeReader $reader,
        int $real_arg_num,
        ?int $func_type,
        ?int $func_last_var = null,
        ?int $func_T = null,
        ?int $func_num_args = null,
    ): array {
        $size = $reader->sizeOf('zend_execute_data');
        [$this_offset] = $reader->getOffsetAndSizeOfMember('zend_execute_data', 'This');
        [$func_offset] = $reader->getOffsetAndSizeOfMember('zend_execute_data', 'func');

        $raw = FFI::cdef()->new("unsigned char[{$size}]");

        // This is a zval (16 B): value (8) + u1 (4) + u2 (4). num_args lives in u2.
        $u2 = pack('V', $real_arg_num);
        for ($b = 0; $b < 4; $b++) {
            $raw[$this_offset + 12 + $b] = ord($u2[$b]);
        }

        // func is a pointer (8 B).
        $func_ptr_bytes = ($func_type !== null)
            ? pack('P', self::FAKE_FUNC_PTR_ADDR)
            : pack('P', 0);
        for ($b = 0; $b < 8; $b++) {
            $raw[$func_offset + $b] = ord($func_ptr_bytes[$b]);
        }

        $casted = $reader->readAs('zend_execute_data', $raw);
        $execute_data = ZendExecuteData::fromCastedCDataWithResolver(
            $casted,
            new Pointer(ZendExecuteData::class, 0x1000, $size),
            new VersionedPointedTypeResolver(self::PHP_VERSION),
        );

        $dereferencer = $func_type === null
            ? $this->createStub(Dereferencer::class)
            : $this->stubDereferencerReturning(
                $this->makeFunction(
                    $reader,
                    $func_type,
                    $func_last_var ?? 0,
                    $func_T ?? 0,
                    $func_num_args ?? 0,
                ),
            );

        return [$execute_data, $dereferencer];
    }

    private function stubDereferencerReturning(ZendFunction $function): Dereferencer
    {
        $stub = $this->createStub(Dereferencer::class);
        $stub->method('deref')->willReturn($function);
        return $stub;
    }

    private function makeFunction(
        ZendTypeReader $reader,
        int $type,
        int $last_var,
        int $T,
        int $num_args,
    ): ZendFunction {
        $size = $reader->sizeOf('zend_function');
        $raw = FFI::cdef()->new("unsigned char[{$size}]");

        // type lives at offset 0 of zend_function (common header byte).
        $raw[0] = $type;

        // op_array fields. Use the union's `op_array` member offset 0
        // since zend_function is a union — op_array starts at the same
        // base address as the union itself; its inner fields are
        // located via the offset-of-member lookup on `zend_op_array`.
        [$last_var_off] = $reader->getOffsetAndSizeOfMember('zend_op_array', 'last_var');
        [$T_off] = $reader->getOffsetAndSizeOfMember('zend_op_array', 'T');
        [$num_args_off] = $reader->getOffsetAndSizeOfMember('zend_op_array', 'num_args');

        $writeU32 = static function (int $offset, int $value) use ($raw): void {
            $bytes = pack('V', $value);
            for ($b = 0; $b < 4; $b++) {
                $raw[$offset + $b] = ord($bytes[$b]);
            }
        };
        $writeU32($last_var_off, $last_var);
        $writeU32($T_off, $T);
        $writeU32($num_args_off, $num_args);

        $casted = $reader->readAs('zend_function', $raw);
        return new ZendFunction(
            $casted,
            new Pointer(ZendFunction::class, self::FAKE_FUNC_PTR_ADDR, $size),
        );
    }

    public function testReturnsZeroWhenFuncIsNull(): void
    {
        $reader = new ZendTypeReader(self::PHP_VERSION);
        [$execute_data, $dereferencer] = $this->makePair($reader, real_arg_num: 3, func_type: null);

        $this->assertSame(0, $execute_data->getTotalVariablesNum($dereferencer));
    }

    public function testInternalFunctionReturnsRealArgNum(): void
    {
        $reader = new ZendTypeReader(self::PHP_VERSION);
        [$execute_data, $dereferencer] = $this->makePair(
            $reader,
            real_arg_num: 4,
            func_type: ZendFunction::ZEND_INTERNAL_FUNCTION,
        );

        // Internal calls skip the formula and just report what the
        // caller pushed.
        $this->assertSame(4, $execute_data->getTotalVariablesNum($dereferencer));
    }

    public function testUserFunctionWithExactArgs(): void
    {
        $reader = new ZendTypeReader(self::PHP_VERSION);
        [$execute_data, $dereferencer] = $this->makePair(
            $reader,
            real_arg_num: 2,
            func_type: ZendFunction::ZEND_USER_FUNCTION,
            func_last_var: 5,
            func_T: 3,
            func_num_args: 2,
        );

        // real == declared: extra is 0; total = last_var + T.
        $this->assertSame(5 + 3, $execute_data->getTotalVariablesNum($dereferencer));
    }

    public function testUserFunctionWithMoreArgsThanDeclared(): void
    {
        $reader = new ZendTypeReader(self::PHP_VERSION);
        [$execute_data, $dereferencer] = $this->makePair(
            $reader,
            real_arg_num: 5,
            func_type: ZendFunction::ZEND_USER_FUNCTION,
            func_last_var: 3,
            func_T: 2,
            func_num_args: 3,
        );

        // real > declared: extra = real - declared = 2; total = 3 + 2 + 2.
        $this->assertSame(3 + 2 + 2, $execute_data->getTotalVariablesNum($dereferencer));
    }

    /**
     * The bug this guards against: the historical formula went
     * negative on optional-arg-omission and undercounted the variable
     * table, surfacing as inter-frame gaps in VM stack accounting.
     */
    public function testUserFunctionWithFewerArgsThanDeclaredDoesNotShrink(): void
    {
        $reader = new ZendTypeReader(self::PHP_VERSION);
        [$execute_data, $dereferencer] = $this->makePair(
            $reader,
            real_arg_num: 1,
            func_type: ZendFunction::ZEND_USER_FUNCTION,
            func_last_var: 5,
            func_T: 4,
            func_num_args: 5,
        );

        // real < declared: extra clamps to 0; total stays at
        // last_var + T regardless of how many optional args were
        // omitted. The pre-fix formula returned 5 + 4 + (1 - 5) = 5.
        $this->assertSame(5 + 4, $execute_data->getTotalVariablesNum($dereferencer));
    }

    /**
     * @return iterable<string, array{int, int, int, int, int}>
     *     [real_arg_num, last_var, T, num_args, expected]
     */
    public static function variantsProvider(): iterable
    {
        // Single optional omission: f($a, $b = null) called as f(1).
        yield 'single optional omitted'         => [1, 2, 1, 2, 3];
        // Multiple optionals omitted: f($a, $b = null, $c = null, $d = null) as f(1).
        yield 'many optionals omitted'          => [1, 4, 2, 4, 6];
        // Exact match.
        yield 'exact match'                     => [3, 3, 5, 3, 8];
        // Extra positional / variadic overflow.
        yield 'one extra arg'                   => [4, 3, 2, 3, 6];
        // No CVs at all (no params, no locals): still includes T.
        yield 'no CVs at all, only T'           => [0, 0, 1, 0, 1];
        // Caller passes args to a no-arg function (rare; e.g. dispatch trampoline).
        yield 'overflow on no-arg declaration'  => [3, 0, 0, 0, 3];
    }

    #[DataProvider('variantsProvider')]
    public function testFormulaVariants(int $real, int $last_var, int $T, int $num_args, int $expected): void
    {
        $reader = new ZendTypeReader(self::PHP_VERSION);
        [$execute_data, $dereferencer] = $this->makePair(
            $reader,
            real_arg_num: $real,
            func_type: ZendFunction::ZEND_USER_FUNCTION,
            func_last_var: $last_var,
            func_T: $T,
            func_num_args: $num_args,
        );

        $this->assertSame($expected, $execute_data->getTotalVariablesNum($dereferencer));
    }
}
