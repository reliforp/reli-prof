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

namespace Reli\Lib\FFI;

use FFI\CData;
use FFI\CInteger;
use FFI\CPointer;

final class FFIHelper
{
    private static ?\FFI $ffi = null;

    private static function ffi(): \FFI
    {
        return self::$ffi ??= \FFI::cdef();
    }

    /**
     * Non-deprecated wrapper around FFI::cast().
     *
     * PHP 8.4 deprecated calling FFI::cast() statically, but the instance
     * method $ffi->cast() is fine.  Using this wrapper avoids tens of
     * thousands of E_DEPRECATED events during memory introspection, which
     * would otherwise blow up PHPUnit's RunTestsInSeparateProcesses
     * serialization.
     *
     * Throws on the rare null return path (e.g. invalid type spec) so the
     * non-nullable return type is honest and callers do not need to write
     * `?? throw new …` at every site — Psalm would otherwise flag those
     * as RedundantCondition / TypeDoesNotContainNull.
     *
     * @param \FFI\CType|non-empty-string $type
     */
    public static function cast(\FFI\CType|string $type, CData &$ptr): CData
    {
        return self::ffi()->cast($type, $ptr)
            ?? throw new CannotCastCDataException(
                'FFI::cast(' . (is_string($type) ? $type : '<CType>') . ') returned null'
            );
    }

    /**
     * @param \FFI\CType|non-empty-string $type
     * @throws CannotAllocateBufferException when FFI::new() returns null
     */
    public static function new(\FFI\CType|string $type, bool $owned = true, bool $persistent = false): CData
    {
        return self::ffi()->new($type, $owned, $persistent)
            ?? throw new CannotAllocateBufferException(
                'FFI::new(' . (is_string($type) ? $type : '<CType>') . ') returned null'
            );
    }

    /**
     * Allocate a fixed-size FFI integer array, typed for Psalm so element
     * access does not need a per-call `(int)` cast. The underlying C type
     * (`int8_t` / `int32_t` / `int64_t` etc.) determines the storage but
     * not the PHP-visible element type — every fixed-width integer field
     * surfaces in PHP as a plain `int` via FFI's automatic conversion, so
     * `\FFI\CArray<int>` is a faithful PHP-side type.
     *
     * The PHP return-type declaration is `\FFI\CData` — the runtime class
     * is `\FFI\CData` regardless of the C type, and `\FFI\CArray` only
     * exists as a project-side Psalm stub. The `@return` docblock carries
     * the templated type for Psalm so callers see int-typed elements.
     *
     * Hot-path callers (BinaryMemoryOutput's CSR build, FfiIntSet's
     * open-addressing slots) use these instead of `FFIHelper::new(...)`
     * to keep the array element type from collapsing to the wider
     * `CData|int|float|bool|null|string` union the upstream stub gives
     * `CData::offsetGet()`.
     *
     * @return \FFI\CArray<int>
     */
    public static function newInt8Array(int $count): \FFI\CData
    {
        /** @var \FFI\CArray<int> */
        return self::new("int8_t[{$count}]");
    }

    /**
     * @return \FFI\CArray<int>
     */
    public static function newInt16Array(int $count): \FFI\CData
    {
        /** @var \FFI\CArray<int> */
        return self::new("int16_t[{$count}]");
    }

    /**
     * @return \FFI\CArray<int>
     */
    public static function newInt32Array(int $count): \FFI\CData
    {
        /** @var \FFI\CArray<int> */
        return self::new("int32_t[{$count}]");
    }

    /**
     * @return \FFI\CArray<int>
     */
    public static function newInt64Array(int $count): \FFI\CData
    {
        /** @var \FFI\CArray<int> */
        return self::new("int64_t[{$count}]");
    }

    /**
     * @return \FFI\CArray<int>
     */
    public static function newUint8Array(int $count): \FFI\CData
    {
        /** @var \FFI\CArray<int> */
        return self::new("uint8_t[{$count}]");
    }

    /**
     * @return \FFI\CArray<int>
     */
    public static function newUint16Array(int $count): \FFI\CData
    {
        /** @var \FFI\CArray<int> */
        return self::new("uint16_t[{$count}]");
    }

    /**
     * @return \FFI\CArray<int>
     */
    public static function newUint32Array(int $count): \FFI\CData
    {
        /** @var \FFI\CArray<int> */
        return self::new("uint32_t[{$count}]");
    }

    /**
     * @return \FFI\CArray<int>
     */
    public static function newUint64Array(int $count): \FFI\CData
    {
        /** @var \FFI\CArray<int> */
        return self::new("uint64_t[{$count}]");
    }

    /**
     * Cast a CData pointer to an `int*` (a `\FFI\CInteger`-shaped view).
     * Typed wrapper around `cast('int', …)` so callers reading `->cdata`
     * get a plain int back instead of mixed.
     *
     * The PHP-level return type stays `\FFI\CData` because `\FFI\CInteger`
     * is a project-side Psalm stub (see tools/stubs/ffi/scalar.php), not
     * a runtime class — declaring it on the signature raises TypeError
     * the same way `\FFI\CArray` does. The `@return` docblock carries
     * the narrowed type for Psalm.
     *
     * @param CData|CInteger|CPointer $ptr
     * @return CInteger
     */
    public static function castToInt(CData &$ptr): CData
    {
        /** @var CInteger */
        return self::cast('int', $ptr);
    }

    /**
     * Cast a CData pointer to a `long*` view. Same role as castToInt
     * but for the wider integer width some stubbed structs declare.
     *
     * @param CData|CInteger|CPointer $ptr
     * @return CInteger
     */
    public static function castToLong(CData &$ptr): CData
    {
        /** @var CInteger */
        return self::cast('long', $ptr);
    }

    /**
     * Cast a C pointer to its integer address value.
     *
     * WARNING: Do NOT pass a void* CData to this method. PHP FFI internally
     * dereferences the pointer during FFI::cast('long', ...) for void*, which
     * causes a SEGV when the pointer holds a remote process address. For void*
     * fields, declare them as uintptr_t in the header instead.
     *
     * @param CPointer|null $cdata
     * @psalm-suppress ReferenceConstraintViolation
     */
    public static function castPointerToInt(?CData &$cdata): int
    {
        if ($cdata === null) {
            return 0;
        }
        /** @var CInteger $casted */
        $casted = self::cast('long', $cdata);
        return $casted->cdata;
    }
}
