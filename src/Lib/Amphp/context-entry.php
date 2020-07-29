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
use PhpProfiler\Lib\Amphp\ContextEntryPointInterface;

return function (Channel $channel) use ($argv): \Generator {
    /**
     * @var class-string<ContextEntryPointInterface> $entry_class
     * @var string $di_config
     */
    [, $entry_class, $di_config] = $argv;
    $container = (new ContainerBuilder())->addDefinitions($di_config)->build();
    /** @var ContextEntryPointInterface $entry_point */
    $entry_point = $container->make($entry_class);
    yield from $entry_point->run($channel);
};
