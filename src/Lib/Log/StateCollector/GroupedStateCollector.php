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

namespace Reli\Lib\Log\StateCollector;

final class GroupedStateCollector implements StateCollector
{
    /** @var StateCollector[] */
    private array $collectors;

    public function __construct(StateCollector ...$collectors)
    {
        $this->collectors = $collectors;
    }

    public function collect(): array
    {
        $result = [];
        foreach ($this->collectors as $collector) {
            $result += $collector->collect();
        }
        return $result;
    }
}
