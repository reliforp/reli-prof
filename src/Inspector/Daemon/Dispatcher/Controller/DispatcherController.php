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

use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Dispatcher\Protocol\DispatcherControllerProtocolInterface;
use PhpProfiler\Inspector\Daemon\Dispatcher\Protocol\Message\SettingsMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Settings\DaemonSettings\DaemonSettings;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use PhpProfiler\Lib\Amphp\ContextInterface;

final class DispatcherController
{
    private ContextInterface $context;

    /**
     * PhpReaderContext constructor.
     * @param ContextInterface<DispatcherControllerProtocolInterface> $context
     */
    public function __construct(ContextInterface $context)
    {
        $this->context = $context;
    }

    public function start(): Promise
    {
        return $this->context->start();
    }

    public function isRunning(): bool
    {
        return $this->context->isRunning();
    }

    public function sendSettings(
        GetTraceSettings $get_trace_settings,
        DaemonSettings $daemon_settings,
        TargetPhpSettings $target_php_settings,
        TraceLoopSettings $trace_loop_settings
    ): Promise {
        return $this->context->getProtocol()->sendSettings(
            new SettingsMessage(
                $get_trace_settings,
                $daemon_settings,
                $target_php_settings,
                $trace_loop_settings
            )
        );
    }

    /**
     * @return Promise<TraceMessage>
     */
    public function getTrace(): Promise
    {
        return $this->context->getProtocol()->getTrace();
    }
}