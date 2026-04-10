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

namespace Reli\Inspector\Watch\Daemon\Controller;

use Reli\Inspector\Daemon\AutoContextRecoveringInterface;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Inspector\Settings\WatchSettings\WatchSettings;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchAttachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchDetachMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchSettingsMessage;
use Reli\Inspector\Watch\Daemon\Protocol\Message\WatchWorkerMessage;
use Reli\Inspector\Watch\Daemon\Protocol\PhpWatchControllerProtocolInterface;

final class PhpWatchController implements PhpWatchControllerInterface
{
    private ?WatchSettingsMessage $settings_already_sent = null;
    private ?WatchAttachMessage $attach_already_sent = null;

    /**
     * @param AutoContextRecoveringInterface<PhpWatchControllerProtocol> $auto_context_recovering
     */
    public function __construct(
        private AutoContextRecoveringInterface $auto_context_recovering,
    ) {
        $this->auto_context_recovering->onRecover(
            function () {
                if ($this->settings_already_sent !== null) {
                    $this->auto_context_recovering
                        ->getContext()
                        ->getProtocol()
                        ->sendSettings($this->settings_already_sent)
                    ;
                }
                if ($this->attach_already_sent !== null) {
                    $this->auto_context_recovering
                        ->getContext()
                        ->getProtocol()
                        ->sendAttach($this->attach_already_sent)
                    ;
                }
            }
        );
    }

    #[\Override]
    public function start(): void
    {
        $this->auto_context_recovering->getContext()->start();
    }

    #[\Override]
    public function isRunning(): bool
    {
        return $this->auto_context_recovering->getContext()->isRunning();
    }

    #[\Override]
    public function sendSettings(
        WatchSettings $watch_settings,
        TraceLoopSettings $loop_settings,
        GetTraceSettings $get_trace_settings,
    ): void {
        $settings_message = new WatchSettingsMessage(
            $watch_settings,
            $loop_settings,
            $get_trace_settings,
        );
        $this->auto_context_recovering->withAutoRecover(
            function (PhpWatchControllerProtocolInterface $protocol) use ($settings_message) {
                $protocol->sendSettings($settings_message);
                $this->settings_already_sent = $settings_message;
            },
            'failed on sending watch settings to worker',
        );
    }

    #[\Override]
    public function sendAttach(
        \Reli\Inspector\Watch\Daemon\Searcher\WatchTargetDescriptor $process_descriptor,
    ): void {
        $attach_message = new WatchAttachMessage($process_descriptor);
        $this->auto_context_recovering->withAutoRecover(
            function (PhpWatchControllerProtocolInterface $protocol) use ($attach_message) {
                $protocol->sendAttach($attach_message);
                $this->attach_already_sent = $attach_message;
            },
            'failed on attaching watch worker',
        );
    }

    #[\Override]
    public function receiveMessage(): WatchWorkerMessage
    {
        return $this->auto_context_recovering->withAutoRecover(
            function (PhpWatchControllerProtocolInterface $protocol): WatchWorkerMessage {
                $message = $protocol->receiveMessage();
                if ($message instanceof WatchDetachMessage) {
                    $this->attach_already_sent = null;
                }
                return $message;
            },
            'failed to receive message from watch worker',
        );
    }
}
