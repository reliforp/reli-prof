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

namespace PhpProfiler\Inspector\Daemon\Reader\Worker;

use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\PhpReaderWorkerProtocolInterface;
use PhpProfiler\Lib\Amphp\WorkerEntryPointInterface;
use PhpProfiler\Lib\Log\Log;

final class PhpReaderEntryPoint implements WorkerEntryPointInterface
{
    public function __construct(
        private PhpReaderTraceLoopInterface $trace_loop,
        private PhpReaderWorkerProtocolInterface $protocol,
    ) {
    }

    public function run(): \Generator
    {
        /**
         * @psalm-ignore-var
         * @var SetSettingsMessage $set_settings_message
         */
        $set_settings_message = yield $this->protocol->receiveSettings();
        Log::debug('settings_message', [$set_settings_message]);

        while (1) {
            /**
             * @psalm-ignore-var
             * @var AttachMessage $attach_message
             */
            $attach_message = yield $this->protocol->receiveAttach();
            Log::debug('attach_message', [$attach_message]);

            try {
                $loop_runner = $this->trace_loop->run(
                    $set_settings_message->trace_loop_settings,
                    $attach_message->process_descriptor,
                    $set_settings_message->get_trace_settings
                );
                Log::debug('start trace');
                foreach ($loop_runner as $message) {
                    yield $this->protocol->sendTrace($message);
                }
                Log::debug('end trace');
            } catch (\Throwable $e) {
                Log::debug('exception thrown at reading traces', [
                    'exception' => $e,
                    'trace' => $e->getTrace(),
                ]);
            }

            Log::debug('detaching worker');
            yield $this->protocol->sendDetachWorker(
                new DetachWorkerMessage($attach_message->process_descriptor->pid)
            );
            Log::debug('detached worker');
        }
    }
}
