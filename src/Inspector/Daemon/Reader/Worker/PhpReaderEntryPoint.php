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

namespace Reli\Inspector\Daemon\Reader\Worker;

use Reli\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use Reli\Inspector\Daemon\Reader\Protocol\PhpReaderWorkerProtocolInterface;
use Reli\Lib\Amphp\WorkerEntryPointInterface;
use Reli\Lib\Log\Log;
use Reli\Lib\Loop\LoopCondition\InfiniteLoopCondition;
use Reli\Lib\Loop\LoopCondition\LoopConditionInterface;

final class PhpReaderEntryPoint implements WorkerEntryPointInterface
{
    public function __construct(
        private PhpReaderTraceLoopInterface $trace_loop,
        private PhpReaderWorkerProtocolInterface $protocol,
        private LoopConditionInterface $loop_condition = new InfiniteLoopCondition(),
    ) {
    }

    public function run(): void
    {
        $set_settings_message = $this->protocol->receiveSettings();
        Log::debug('settings_message', [$set_settings_message]);

        while ($this->loop_condition->shouldContinue()) {
            $attach_message = $this->protocol->receiveAttach();
            Log::debug('attach_message', [$attach_message]);

            try {
                $loop_runner = $this->trace_loop->run(
                    $set_settings_message->trace_loop_settings,
                    $attach_message->process_descriptor,
                    $set_settings_message->get_trace_settings
                );
                Log::debug('start trace');
                foreach ($loop_runner as $message) {
                    $this->protocol->sendTrace($message);
                }
                Log::debug('end trace');
            } catch (\Throwable $e) {
                Log::debug('exception thrown at reading traces', [
                    'exception' => $e,
                    'trace' => $e->getTrace(),
                ]);
            }

            Log::debug('detaching worker');
            $this->protocol->sendDetachWorker(
                new DetachWorkerMessage($attach_message->process_descriptor->pid)
            );
            Log::debug('detached worker');
        }
    }
}
