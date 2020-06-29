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

namespace PhpProfiler\Inspector\Daemon\Reader\Context;

use Amp\Parallel\Context;
use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Dispatcher\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Dispatcher\Message\TraceMessage;
use PhpProfiler\Inspector\Daemon\Reader\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Message\SetSettingsMessage;
use PhpProfiler\Inspector\Settings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings;

final class PhpReaderContext
{
    private Context\Context $context;

    public function __construct(Context\Context $context)
    {
        $this->context = $context;
    }

    public function start(): Promise
    {
        return $this->context->start();
    }

    /**
     * @param TargetPhpSettings $target_php_settings
     * @param TraceLoopSettings $loop_settings
     * @param GetTraceSettings $get_trace_settings
     * @return Promise<int>
     */
    public function sendSettings(
        TargetPhpSettings $target_php_settings,
        TraceLoopSettings $loop_settings,
        GetTraceSettings $get_trace_settings
    ): Promise {
        /** @var Promise<int> */
        return $this->context->send(
            new SetSettingsMessage(
                $target_php_settings,
                $loop_settings,
                $get_trace_settings
            )
        );
    }

    /**
     * @param int $pid
     * @return Promise<int>
     */
    public function sendAttach(int $pid): Promise
    {
        /** @var Promise<int> */
        return $this->context->send(
            new AttachMessage($pid)
        );
    }

    public function isRunning(): bool
    {
        return $this->context->isRunning();
    }

    /**
     * @return Promise<TraceMessage|DetachWorkerMessage>
     * @psalm-yield Promise<TraceMessage|DetachWorkerMessage>
     */
    public function receiveTrace(): Promise
    {
        /** @var Promise<TraceMessage|DetachWorkerMessage> */
        return $this->context->receive();
    }
}
