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

namespace Reli\Inspector\Watch\Daemon\Worker;

use Reli\Lib\Loop\LoopCondition\LoopConditionInterface;

final class CountdownLoopCondition implements LoopConditionInterface
{
    public function __construct(
        private int &$count,
        private int $max,
    ) {
    }

    public function shouldContinue(): bool
    {
        return $this->count++ < $this->max;
    }
}
