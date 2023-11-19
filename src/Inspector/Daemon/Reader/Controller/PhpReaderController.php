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

namespace Reli\Inspector\Daemon\Reader\Controller;

use Reli\Inspector\Daemon\AutoContextRecoveringInterface;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use Reli\Inspector\Daemon\Reader\Protocol\PhpReaderControllerProtocolInterface;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;

final class PhpReaderController implements PhpReaderControllerInterface
{
    private ?SetSettingsMessage $settings_already_sent = null;
    private ?AttachMessage $attach_already_sent = null;

    /**
     * @param AutoContextRecoveringInterface<PhpReaderControllerProtocol> $auto_context_recovering
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

    public function start(): void
    {
        $this->auto_context_recovering->getContext()->start();
    }

    public function isRunning(): bool
    {
        return $this->auto_context_recovering->getContext()->isRunning();
    }

    public function sendSettings(
        TraceLoopSettings $loop_settings,
        GetTraceSettings $get_trace_settings
    ): void {
        $settings_message = new SetSettingsMessage(
            $loop_settings,
            $get_trace_settings
        );
        $this->auto_context_recovering->withAutoRecover(
            function (PhpReaderControllerProtocolInterface $protocol) use ($settings_message) {
                $protocol->sendSettings($settings_message);
                $this->settings_already_sent = $settings_message;
            },
            'failed on sending settings to worker'
        );
    }

    public function sendAttach(TargetProcessDescriptor $process_descriptor): void
    {
        $attach_message = new AttachMessage($process_descriptor);
        $this->auto_context_recovering->withAutoRecover(
            function (PhpReaderControllerProtocolInterface $protocol) use ($attach_message) {
                $protocol->sendAttach($attach_message);
                $this->attach_already_sent = $attach_message;
            },
            'failed on attaching worker'
        );
    }

    public function receiveTraceOrDetachWorker(): TraceMessage|DetachWorkerMessage
    {
        return $this->auto_context_recovering->withAutoRecover(
            function (PhpReaderControllerProtocolInterface $protocol): TraceMessage|DetachWorkerMessage {
                $message = $protocol->receiveTraceOrDetachWorker();
                if ($message instanceof DetachWorkerMessage) {
                    $this->attach_already_sent = null;
                }
                return $message;
            },
            'failed to receive trace or detach worker'
        );
    }
}
