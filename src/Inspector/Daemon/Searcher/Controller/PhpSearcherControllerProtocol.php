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

namespace Reli\Inspector\Daemon\Searcher\Controller;

use Amp\Sync\Channel;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\PhpSearcherControllerProtocolInterface;

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

    public function sendTargetRegex(TargetPhpSettingsMessage $message): void
    {
        $this->channel->send($message);
    }

    public function receiveUpdateTargetProcess(): UpdateTargetProcessMessage
    {
        /** @var UpdateTargetProcessMessage */
        return $this->channel->receive();
    }
}
