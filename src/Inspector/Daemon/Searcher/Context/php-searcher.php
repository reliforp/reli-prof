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
use PhpProfiler\Lib\Concurrency\Amphp\Task\PhpSearcherTask;

return function (Channel $channel): \Generator {
    $container = (new ContainerBuilder())->addDefinitions(__DIR__ . '/../../../../../config/di.php')->build();

    /** @var PhpSearcherTask $searcher */
    $searcher = $container->make(PhpSearcherTask::class, ['channel' => $channel]);

    /** @var string $target_regex */
    $target_regex = yield $channel->receive();

    while (1) {
        yield from $searcher->run($target_regex);
    }
};
