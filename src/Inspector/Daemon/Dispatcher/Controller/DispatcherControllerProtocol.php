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

namespace PhpProfiler\Inspector\Daemon\Dispatcher\Controller;

use Amp\Parallel\Sync\Channel;
use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Dispatcher\Protocol\DispatcherControllerProtocolInterface;
use PhpProfiler\Inspector\Daemon\Dispatcher\Protocol\Message\SettingsMessage;

final class DispatcherControllerProtocol implements DispatcherControllerProtocolInterface
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

    public function sendSettings(
        SettingsMessage $settings_message
    ): Promise
    {
        return $this->channel->send($settings_message);
    }

    public function getTrace(): Promise
    {
        return $this->channel->receive();
    }
}