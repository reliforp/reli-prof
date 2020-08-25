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

use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\TargetRegexMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\PhpSearcherControllerProtocolInterface;

final class PhpSearcherControllerProtocol implements PhpSearcherControllerProtocolInterface
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

    public function sendTargetRegex(TargetRegexMessage $message): Promise
    {
        return $this->channel->send($message);
    }

    public function receiveUpdateTargetProcess(): Promise
    {
        /** @var Promise<UpdateTargetProcessMessage> */
        return $this->channel->receive();
    }
}
