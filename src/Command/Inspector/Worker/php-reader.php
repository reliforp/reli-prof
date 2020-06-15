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
use PhpProfiler\Command\Inspector\Settings\GetTraceSettings;
use PhpProfiler\Command\Inspector\Settings\TargetPhpSettings;
use PhpProfiler\Command\Inspector\Settings\TargetProcessSettings;
use PhpProfiler\Command\Inspector\Settings\TraceLoopSettings;
use PhpProfiler\Lib\Concurrency\Amphp\Task\PhpReaderTask;

return function (Channel $channel): \Generator {

    $container = (new ContainerBuilder())->addDefinitions(__DIR__ . '/../../../../config/di.php')->build();

    /** @var PhpReaderTask $reader */
    $reader = $container->make(PhpReaderTask::class, ['channel' => $channel]);

    /** @var int $pid */
    $pid = yield $channel->receive();

    $trace_loop_settings = new TraceLoopSettings(
        10 * 1000 * 1000,
        'q',
        10
    );
    $target_process_settings = new TargetProcessSettings(
        $pid
    );
    $target_php_settings = new TargetPhpSettings();
    $get_trace_settings = new GetTraceSettings(
        PHP_INT_MAX
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
