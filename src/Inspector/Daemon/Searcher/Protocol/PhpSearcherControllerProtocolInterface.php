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

namespace PhpProfiler\Inspector\Daemon\Searcher\Protocol;

use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\TargetRegexMessage;
use PhpProfiler\Lib\Amphp\MessageProtocolInterface;

interface PhpSearcherControllerProtocolInterface extends MessageProtocolInterface
{
    /**
     * @param TargetRegexMessage $message
     * @return Promise<int>
     */
    public function sendTargetRegex(TargetRegexMessage $message): Promise;

    /**
     * @return Promise<UpdateTargetProcessMessage>
     */
    public function receiveUpdateTargetProcess(): Promise;
}
