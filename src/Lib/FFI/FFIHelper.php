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
     * @template T of CData
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
