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

namespace Reli\Lib\Loop\LoopCondition;

final class OnlyOnceCondition implements LoopConditionInterface
{
    private bool $has_run = false;

    public function shouldContinue(): bool
    {
        if ($this->has_run) {
            return false;
        }
        $this->has_run = true;
        return true;
    }
}
