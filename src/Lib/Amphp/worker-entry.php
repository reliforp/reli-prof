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
use PhpProfiler\Lib\Amphp\WorkerEntryPointInterface;
use PhpProfiler\Lib\Amphp\MessageProtocolInterface;
use PhpProfiler\Lib\Log\Log;
use PhpProfiler\Lib\Log\StateCollector\StateCollector;
use Psr\Log\LoggerInterface;

return function (Channel $channel) use ($argv): \Generator {
    /**
     * @var class-string<WorkerEntryPointInterface> $entry_class
     * @var class-string<MessageProtocolInterface> $protocol_class
     * @var string $di_config
     */
    [, $entry_class, $protocol_class, $di_config] = $argv;
    $container = (new ContainerBuilder())->addDefinitions($di_config)->build();
    /** @var LoggerInterface $logger */
    $logger = $container->make(LoggerInterface::class);
    /** @var StateCollector $state_collector */
    $state_collector = $container->make(StateCollector::class);
    Log::initializeLogger($logger, $state_collector);
    /** @var MessageProtocolInterface $protocol */
    $protocol = $container->make($protocol_class, ['channel' => $channel]);
    /** @var WorkerEntryPointInterface $entry_point */
    $entry_point = $container->make($entry_class, ['protocol' => $protocol]);
    yield from $entry_point->run();
};
