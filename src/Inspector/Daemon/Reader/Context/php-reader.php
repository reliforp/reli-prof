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
use PhpProfiler\Inspector\Settings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TargetProcessSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings;
use PhpProfiler\Inspector\Daemon\Reader\PhpReaderTask;

return function (Channel $channel): \Generator {

    $container = (new ContainerBuilder())->addDefinitions(__DIR__ . '/../../../../../config/di.php')->build();

    /** @var PhpReaderTask $reader */
    $reader = $container->make(PhpReaderTask::class, ['channel' => $channel]);

    /** @var array $start_message */
    $start_message = yield $channel->receive();
    /**
     * @var int $pid
     * @var TargetPhpSettings $target_php_settings
     * @var TraceLoopSettings $trace_loop_settings
     * @var GetTraceSettings $get_trace_settings
     */
    [$pid, $target_php_settings, $trace_loop_settings, $get_trace_settings] = $start_message;

    $target_process_settings = new TargetProcessSettings(
        $pid
    );

    while (1) {
        yield from $reader->run(
            $trace_loop_settings,
            $target_process_settings,
            $target_php_settings,
            $get_trace_settings
        );
    }
};
