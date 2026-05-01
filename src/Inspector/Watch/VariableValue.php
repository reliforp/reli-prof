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

namespace Reli\Inspector\Watch;

/**
 * Represents a variable value read from a target process.
 *
 * Scalars use $scalar_value. Arrays and objects use $children
 * (a list of [key, VariableValue] pairs); $array_count holds the
 * total element count even when $children is truncated or absent
 * (e.g. depth-limited shallow read).
 */
final class VariableValue
{
    public const TYPE_LONG = 'long';
    public const TYPE_DOUBLE = 'double';
    public const TYPE_STRING = 'string';
    public const TYPE_BOOL = 'bool';
    public const TYPE_ARRAY = 'array';
    public const TYPE_OBJECT = 'object';
    public const TYPE_NULL = 'null';
    public const TYPE_RECURSION = 'recursion';
    public const TYPE_UNKNOWN = 'unknown';

    /**
     * @param list<array{0: int|string, 1: VariableValue}>|null $children
     */
    public function __construct(
        public readonly string $type,
        public readonly int|float|string|bool|null $scalar_value,
        public readonly ?int $array_count,
        public readonly ?array $children = null,
        public readonly ?string $class_name = null,
        public readonly ?int $object_id = null,
        public readonly bool $children_truncated = false,
        public readonly bool $string_truncated = false,
        public readonly ?int $original_string_length = null,
    ) {
    }
}
