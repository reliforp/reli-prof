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

use Amp\Parallel\Sync\Channel;
use DI\ContainerBuilder;
use PhpProfiler\Inspector\Daemon\Dispatcher\Message\DetachWorkerMessage;
use PhpProfiler\Inspector\Daemon\Reader\Message\AttachMessage;
use PhpProfiler\Inspector\Daemon\Reader\Message\SetSettingsMessage;
use PhpProfiler\Inspector\Settings\TargetProcessSettings;
use PhpProfiler\Inspector\Daemon\Reader\PhpReaderTask;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;

return function (Channel $channel): \Generator {

    $container = (new ContainerBuilder())->addDefinitions(__DIR__ . '/../../../../../config/di.php')->build();

    /** @var PhpReaderTask $reader */
    $reader = $container->make(PhpReaderTask::class, ['channel' => $channel]);

    /** @var SetSettingsMessage $set_settings_message */
    $set_settings_message = yield $channel->receive();

    while (1) {
        /** @var AttachMessage $attach_message */
        $attach_message = yield $channel->receive();

        $target_process_settings = new TargetProcessSettings(
            $attach_message->pid
        );

        try {
            yield from $reader->run(
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
};
