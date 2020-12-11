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

namespace PhpProfiler\Inspector\Daemon\Dispatcher\Worker;

use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Dispatcher\Protocol\DispatcherWorkerProtocolInterface;
use PhpProfiler\Inspector\Daemon\Dispatcher\Protocol\Message\SettingsMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;

final class DispatcherWorkerProtocol implements DispatcherWorkerProtocolInterface
{
    private Channel $channel;

    public function __construct(Channel $channel)
    {
        $this->channel = $channel;
    }

    public static function createFromChannel(Channel $channel): self
    {
        return new self($channel);
    }

    public function getSettings(): Promise
    {
        /** @var Promise<SettingsMessage> */
        return $this->channel->receive();
    }


    public function sendTraces(TraceMessage $trace_message): Promise
    {
        return $this->channel->send($trace_message);
    }
}