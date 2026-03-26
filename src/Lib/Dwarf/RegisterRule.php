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

final class RegisterRule
{
    private function __construct(
        public readonly RegisterRuleType $type,
        public readonly int $value,
        public readonly string $expressionBytes,
    ) {
    }

    public static function undefined(): self
    {
        return new self(RegisterRuleType::Undefined, 0, '');
    }

    public static function sameValue(): self
    {
        return new self(RegisterRuleType::SameValue, 0, '');
    }

    public static function offset(int $offset): self
    {
        return new self(RegisterRuleType::Offset, $offset, '');
    }

    public static function valOffset(int $offset): self
    {
        return new self(RegisterRuleType::ValOffset, $offset, '');
    }

    public static function register(int $register): self
    {
        return new self(RegisterRuleType::Register, $register, '');
    }

    public static function expression(string $bytes): self
    {
        return new self(RegisterRuleType::Expression, 0, $bytes);
    }

    public static function valExpression(string $bytes): self
    {
        return new self(RegisterRuleType::ValExpression, 0, $bytes);
    }
}
