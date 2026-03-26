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

final class UnwindTableRow
{
    /**
     * @param array<int, RegisterRule> $registerRules
     */
    public function __construct(
        public readonly int $location,
        public readonly CfaRule $cfaRule,
        public readonly array $registerRules,
    ) {
    }

    public function getRegisterRule(int $register): RegisterRule
    {
        return $this->registerRules[$register] ?? RegisterRule::undefined();
    }

    /**
     * @param array<int, RegisterRule> $registerRules
     */
    public function withUpdated(
        ?int $location = null,
        ?CfaRule $cfaRule = null,
        ?array $registerRules = null,
    ): self {
        return new self(
            $location ?? $this->location,
            $cfaRule ?? $this->cfaRule,
            $registerRules ?? $this->registerRules,
        );
    }
}
