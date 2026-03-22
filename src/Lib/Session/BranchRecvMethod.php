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

namespace Reli\Lib\Session;

final class BranchRecvMethod
{
    /**
     * @param string $state The state name where branching recv occurs
     * @param string $methodName The generated receive method name (e.g. receiveTraceOrDetachWorker)
     * @param list<class-string> $messageClasses FQCN of all possible message types
     * @param list<string> $messageClassShortNames Short class names
     */
    public function __construct(
        public readonly string $state,
        public readonly string $methodName,
        public readonly array $messageClasses,
        public readonly array $messageClassShortNames,
    ) {
    }
}
