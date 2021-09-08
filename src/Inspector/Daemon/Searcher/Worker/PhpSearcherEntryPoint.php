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

namespace PhpProfiler\Inspector\Daemon\Searcher\Worker;

use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessList;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\TargetRegexMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\PhpSearcherWorkerProtocolInterface;
use PhpProfiler\Lib\Amphp\WorkerEntryPointInterface;
use PhpProfiler\Lib\Process\Search\ProcessSearcherInterface;

use function sleep;

final class PhpSearcherEntryPoint implements WorkerEntryPointInterface
{
    public function __construct(
        private PhpSearcherWorkerProtocolInterface $protocol,
        private ProcessSearcherInterface $process_searcher
    ) {
    }

    public function run(): \Generator
    {
        /**
         * @psalm-ignore-var
         * @var TargetRegexMessage $target_regex
         */
        $target_regex = yield $this->protocol->receiveTargetRegex();

        while (1) {
            yield $this->protocol->sendUpdateTargetProcess(
                new UpdateTargetProcessMessage(
                    new TargetProcessList(
                        ...$this->process_searcher->searchByRegex($target_regex->regex)
                    )
                )
            );
            sleep(1);
        }
    }
}
