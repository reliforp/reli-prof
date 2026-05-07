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
        public readonly ?int $address = null,
        public readonly ?int $reference_address = null,
    ) {
    }

    /**
     * Return a copy with `reference_address` replaced. Used by the reader
     * to stamp the originating zend_reference's pointer onto a value
     * after resolveIndirectAndRef has already consumed the IS_REFERENCE
     * layer.
     */
    public function withReferenceAddress(?int $reference_address): self
    {
        return new self(
            $this->type,
            $this->scalar_value,
            $this->array_count,
            $this->children,
            $this->class_name,
            $this->object_id,
            $this->children_truncated,
            $this->string_truncated,
            $this->original_string_length,
            $this->address,
            $reference_address,
        );
    }
}
