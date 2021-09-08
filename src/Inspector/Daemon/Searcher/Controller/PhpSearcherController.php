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
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\TargetRegexMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\PhpSearcherControllerProtocolInterface;
use PhpProfiler\Lib\Amphp\ContextInterface;

final class PhpSearcherController implements PhpSearcherControllerInterface
{
    /** @param ContextInterface<PhpSearcherControllerProtocolInterface> $context */
    public function __construct(
        private ContextInterface $context
    ) {
    }

    public function start(): Promise
    {
        return $this->context->start();
    }

    public function sendTargetRegex(string $regex): Promise
    {
        return $this->context->getProtocol()
            ->sendTargetRegex(
                new TargetRegexMessage($regex)
            )
        ;
    }

    public function receivePidList(): Promise
    {
        return $this->context->getProtocol()->receiveUpdateTargetProcess();
    }
}
