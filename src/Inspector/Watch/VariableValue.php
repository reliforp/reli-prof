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
 */
final class VariableValue
{
    public const TYPE_LONG = 'long';
    public const TYPE_DOUBLE = 'double';
    public const TYPE_STRING = 'string';
    public const TYPE_BOOL = 'bool';
    public const TYPE_ARRAY = 'array';
    public const TYPE_NULL = 'null';
    public const TYPE_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $type,
        public readonly int|float|string|bool|null $scalar_value,
        public readonly ?int $array_count,
    ) {
    }
}
