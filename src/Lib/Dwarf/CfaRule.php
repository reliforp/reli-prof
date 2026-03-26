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

final class CfaRule
{
    private function __construct(
        public readonly CfaRuleType $type,
        public readonly int $register,
        public readonly int $offset,
        public readonly string $expressionBytes,
    ) {
    }

    public static function registerOffset(int $register, int $offset): self
    {
        return new self(CfaRuleType::RegisterOffset, $register, $offset, '');
    }

    public static function expression(string $expressionBytes): self
    {
        return new self(CfaRuleType::Expression, 0, 0, $expressionBytes);
    }
}
