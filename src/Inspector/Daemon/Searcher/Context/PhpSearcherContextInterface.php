<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Daemon\Searcher\Context;

use Amp\Promise;

interface PhpSearcherContextInterface
{
    /**
     * @return Promise<null>
     */
    public function start(): Promise;

    /**
     * @param string $regex
     * @return Promise<int>
     */
    public function sendTargetRegex(string $regex): Promise;

    /**
     * @return Promise<UpdateTargetProcessMessage>
     * @psalm-yield Promise<UpdateTargetProcessMessage>
     */
    public function receivePidList(): Promise;
}