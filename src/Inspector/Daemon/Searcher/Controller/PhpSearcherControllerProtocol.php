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

namespace PhpProfiler\Inspector\Daemon\Searcher\Controller;

use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\PhpSearcherControllerProtocolInterface;

final class PhpSearcherControllerProtocol implements PhpSearcherControllerProtocolInterface
{
    public function __construct(
        private Channel $channel
    ) {
    }

    public static function createFromChannel(Channel $channel): static
    {
        return new self($channel);
    }

    public function sendTargetRegex(TargetPhpSettingsMessage $message): Promise
    {
        return $this->channel->send($message);
    }

    public function receiveUpdateTargetProcess(): Promise
    {
        /** @var Promise<UpdateTargetProcessMessage> */
        return $this->channel->receive();
    }
}
