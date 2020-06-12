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

namespace PhpProfiler\Lib\Concurrency\Amphp\Task;

use Amp\Parallel\Sync\Channel;
use Generator;
use PhpProfiler\Lib\Process\Search\ProcessSearcher;

final class PhpSearcherTask
{
    private Channel $channel;
    private ProcessSearcher $process_searcher;

    public function __construct(Channel $channel, ProcessSearcher $process_searcher)
    {
        $this->channel = $channel;
        $this->process_searcher = $process_searcher;
    }

    public function run(string $target_regex): Generator
    {
        while (1) {
            yield $this->channel->send(
                $this->process_searcher->searchByRegex($target_regex)
            );
            sleep(1);
        }
    }
}
