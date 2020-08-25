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

interface PhpSearcherWorkerProtocolInterface extends MessageProtocolInterface
{
    /**
     * @return Promise<TargetRegexMessage>
     */
    public function receiveTargetRegex(): Promise;

    public function sendUpdateTargetProcess(UpdateTargetProcessMessage $message): Promise;
}
