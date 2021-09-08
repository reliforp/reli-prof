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

namespace PhpProfiler\Inspector\Daemon\Searcher\Controller;

use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;

interface PhpSearcherControllerInterface
{
    /** @return Promise<null> */
    public function start(): Promise;

    /** @return Promise<int> */
    public function sendTargetRegex(string $regex): Promise;

    /** @return Promise<UpdateTargetProcessMessage> */
    public function receivePidList(): Promise;
}
