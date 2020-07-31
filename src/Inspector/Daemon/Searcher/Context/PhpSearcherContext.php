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

use Amp\Parallel\Context;
use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Dispatcher\Message\UpdateTargetProcessMessage;

final class PhpSearcherContext implements PhpSearcherContextInterface
{
    private Context\Context $context;

    public function __construct(Context\Context $context)
    {
        $this->context = $context;
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
        return $this->context->send($regex);
    }

    /**
     * @return Promise<UpdateTargetProcessMessage>
     * @psalm-yield Promise<UpdateTargetProcessMessage>
     */
    public function receivePidList(): Promise
    {
        /** @var Promise<UpdateTargetProcessMessage> */
        return $this->context->receive();
    }
}
