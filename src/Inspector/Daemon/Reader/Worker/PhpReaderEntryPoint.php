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

namespace PhpProfiler\Inspector\Daemon\Reader\Worker;

use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\SetSettingsMessage;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\PhpReaderWorkerProtocolInterface;
use PhpProfiler\Inspector\Settings\TargetProcessSettings\TargetProcessSettings;
use PhpProfiler\Lib\Amphp\WorkerEntryPointInterface;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;

final class PhpReaderEntryPoint implements WorkerEntryPointInterface
{
    public function __construct(
        private PhpReaderTraceLoopInterface $trace_loop,
        private PhpReaderWorkerProtocolInterface $protocol
    ) {
    }

    public function run(): \Generator
    {
        /**
         * @psalm-ignore-var
         * @var SetSettingsMessage $set_settings_message
         */
        $set_settings_message = yield $this->protocol->receiveSettings();

        while (1) {
            /**
             * @psalm-ignore-var
             * @var AttachMessage $attach_message
             */
            $attach_message = yield $this->protocol->receiveAttach();

            $target_process_settings = new TargetProcessSettings(
                $attach_message->pid
            );

            try {
                $loop_runner = $this->trace_loop->run(
                    $target_process_settings,
                    $set_settings_message->trace_loop_settings,
                    $set_settings_message->target_php_settings,
                    $set_settings_message->get_trace_settings
                );
                foreach ($loop_runner as $message) {
                    yield $this->protocol->sendTrace($message);
                }
            } catch (MemoryReaderException $e) {
                // TODO: log errors
            }

            yield $this->protocol->sendDetachWorker(
                new DetachWorkerMessage($attach_message->pid)
            );
        }
    }
}
