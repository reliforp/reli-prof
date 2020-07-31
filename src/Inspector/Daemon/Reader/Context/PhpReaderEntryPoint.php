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

use Amp\Parallel\Sync\Channel;
use PhpProfiler\Inspector\Daemon\Dispatcher\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Reader\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Message\SetSettingsMessage;
use PhpProfiler\Inspector\Settings\TargetProcessSettings;
use PhpProfiler\Inspector\Daemon\Reader\PhpReaderTaskInterface;
use PhpProfiler\Lib\Amphp\ContextEntryPointInterface;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;

final class PhpReaderEntryPoint implements ContextEntryPointInterface
{
    private PhpReaderTaskInterface $php_reader_task;

    public function __construct(PhpReaderTaskInterface $php_reader_task)
    {
        $this->php_reader_task = $php_reader_task;
    }

    public function run(Channel $channel): \Generator
    {
        /** @var SetSettingsMessage $set_settings_message */
        $set_settings_message = yield $channel->receive();

        while (1) {
            /** @var AttachMessage $attach_message */
            $attach_message = yield $channel->receive();

            $target_process_settings = new TargetProcessSettings(
                $attach_message->pid
            );

            try {
                yield from $this->php_reader_task->run(
                    $channel,
                    $target_process_settings,
                    $set_settings_message->trace_loop_settings,
                    $set_settings_message->target_php_settings,
                    $set_settings_message->get_trace_settings
                );
            } catch (MemoryReaderException $e) {
                // TODO: log errors
            }

            yield $channel->send(
                new DetachWorkerMessage($attach_message->pid)
            );
        }
    }
}
