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

namespace PhpProfiler\Inspector\Daemon\Searcher\Context;

use Amp\Parallel\Sync\Channel;
use PhpProfiler\Inspector\Daemon\Dispatcher\Message\UpdateTargetProcessMessage;
use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessList;
use PhpProfiler\Lib\Amphp\ContextEntryPointInterface;
use PhpProfiler\Lib\Process\Search\ProcessSearcherInterface;

final class PhpSearcherEntryPoint implements ContextEntryPointInterface
{
    private ProcessSearcherInterface $process_searcher;

    public function __construct(ProcessSearcherInterface $process_searcher)
    {
        $this->process_searcher = $process_searcher;
    }

    public function run(Channel $channel): \Generator
    {
        /** @var string $target_regex */
        $target_regex = yield $channel->receive();

        while (1) {
            yield $channel->send(
                new UpdateTargetProcessMessage(
                    new TargetProcessList(
                        ...$this->process_searcher->searchByRegex($target_regex)
                    )
                )
            );
            sleep(1);
        }
    }
}
