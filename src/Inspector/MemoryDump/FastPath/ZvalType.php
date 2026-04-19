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

namespace Reli\Inspector\MemoryDump\FastPath;

/**
 * Zval type constants matching PHP's zend_types.h.
 * Used by the fast path to avoid string-based type comparisons.
 */
final class ZvalType
{
    public const IS_UNDEF = 0;
    public const IS_NULL = 1;
    public const IS_FALSE = 2;
    public const IS_TRUE = 3;
    public const IS_LONG = 4;
    public const IS_DOUBLE = 5;
    public const IS_STRING = 6;
    public const IS_ARRAY = 7;
    public const IS_OBJECT = 8;
    public const IS_RESOURCE = 9;
    public const IS_REFERENCE = 10;
    public const IS_INDIRECT = 12;

    public static function isHeapType(int $type): bool
    {
        return $type >= self::IS_STRING && $type !== self::IS_INDIRECT;
    }

    public static function toName(int $type): string
    {
        return match ($type) {
            self::IS_UNDEF => 'IS_UNDEF',
            self::IS_NULL => 'IS_NULL',
            self::IS_FALSE => 'IS_FALSE',
            self::IS_TRUE => 'IS_TRUE',
            self::IS_LONG => 'IS_LONG',
            self::IS_DOUBLE => 'IS_DOUBLE',
            self::IS_STRING => 'IS_STRING',
            self::IS_ARRAY => 'IS_ARRAY',
            self::IS_OBJECT => 'IS_OBJECT',
            self::IS_RESOURCE => 'IS_RESOURCE',
            self::IS_REFERENCE => 'IS_REFERENCE',
            self::IS_INDIRECT => 'IS_INDIRECT',
            default => 'UNKNOWN(' . $type . ')',
        };
    }
}
