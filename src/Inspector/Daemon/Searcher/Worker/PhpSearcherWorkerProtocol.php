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

namespace Reli\Inspector\Daemon\Searcher\Worker;

use Amp\Sync\Channel;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\PhpSearcherWorkerProtocolInterface;

final class PhpSearcherWorkerProtocol implements PhpSearcherWorkerProtocolInterface
{
    public function __construct(
        private Channel $channel
    ) {
    }

    public static function createFromChannel(Channel $channel): static
    {
        return new self($channel);
    }

    public function receiveTargetPhpSettings(): TargetPhpSettingsMessage
    {
        /** @var TargetPhpSettingsMessage */
        return $this->channel->receive();
    }

    public function sendUpdateTargetProcess(UpdateTargetProcessMessage $message): void
    {
        $this->channel->send($message);
    }
}
