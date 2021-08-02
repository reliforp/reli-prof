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
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\TargetRegexMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\PhpSearcherControllerProtocolInterface;
use PhpProfiler\Lib\Amphp\ContextInterface;

final class PhpSearcherController implements PhpSearcherControllerInterface
{
    /**
     * PhpSearcherContext constructor.
     * @param ContextInterface<PhpSearcherControllerProtocolInterface> $context
     */
    public function __construct(
        private ContextInterface $context
    ) {
    }

    /**
     * @return Promise<null>
     */
    public function start(): Promise
    {
        return $this->context->start();
    }

    /**
     * @param string $regex
     * @return Promise<int>
     */
    public function sendTargetRegex(string $regex): Promise
    {
        /** @var Promise<int> */
        return $this->context->getProtocol()
            ->sendTargetRegex(
                new TargetRegexMessage($regex)
            )
        ;
    }

    /**
     * @return Promise<UpdateTargetProcessMessage>
     */
    public function receivePidList(): Promise
    {
        /** @var Promise<UpdateTargetProcessMessage> */
        return $this->context->getProtocol()->receiveUpdateTargetProcess();
    }
}
