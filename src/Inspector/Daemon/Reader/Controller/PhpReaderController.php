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

use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use Reli\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use Reli\Inspector\Daemon\Reader\Protocol\PhpReaderControllerProtocolInterface;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Reli\Lib\Amphp\ContextInterface;

final class PhpReaderController implements PhpReaderControllerInterface
{
    /** @param ContextInterface<PhpReaderControllerProtocolInterface> $context */
    public function __construct(
        private ContextInterface $context
    ) {
    }

    public function start(): void
    {
        $this->context->start();
    }

    public function isRunning(): bool
    {
        return $this->context->isRunning();
    }

    public function sendSettings(
        TraceLoopSettings $loop_settings,
        GetTraceSettings $get_trace_settings
    ): void {
        $this->context->getProtocol()->sendSettings(
            new SetSettingsMessage(
                $loop_settings,
                $get_trace_settings
            )
        );
    }

    public function sendAttach(TargetProcessDescriptor $process_descriptor): void
    {
        $this->context->getProtocol()->sendAttach(
            new AttachMessage($process_descriptor)
        );
    }

    public function receiveTraceOrDetachWorker(): TraceMessage|DetachWorkerMessage
    {
        return $this->context->getProtocol()->receiveTraceOrDetachWorker();
    }
}
