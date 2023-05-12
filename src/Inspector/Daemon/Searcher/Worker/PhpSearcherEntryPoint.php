<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Reli\Inspector\Daemon\Searcher\Worker;

use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessList;
use Reli\Inspector\Daemon\Searcher\Protocol\PhpSearcherWorkerProtocolInterface;
use Reli\Lib\Amphp\WorkerEntryPointInterface;
use Reli\Lib\Loop\LoopCondition\InfiniteLoopCondition;
use Reli\Lib\Loop\LoopCondition\LoopConditionInterface;
use Reli\Lib\Process\ProcFileSystem\ThreadEnumerator;
use Reli\Lib\Process\Search\ProcessSearcherInterface;

use function sleep;

final class PhpSearcherEntryPoint implements WorkerEntryPointInterface
{
    public function __construct(
        private PhpSearcherWorkerProtocolInterface $protocol,
        private ProcessSearcherInterface $process_searcher,
        private ProcessDescriptorRetriever $process_descriptor_retriever,
        private ThreadEnumerator $thread_enumerator,
        private LoopConditionInterface $loop_condition = new InfiniteLoopCondition(),
    ) {
    }

    public function run(): void
    {
        $target_php_settings_message = $this->protocol->receiveTargetPhpSettings();
        $cache = new ProcessDescriptorCache();

        while ($this->loop_condition->shouldContinue()) {
            $searched_pids = array_diff(
                $this->process_searcher->searchByRegex(
                    $target_php_settings_message->regex
                ),
                [
                    ...$this->thread_enumerator->getThreadIds(
                        $target_php_settings_message->parent_pid
                    )
                ],
            );
            $cache->removeDisappeared(...$searched_pids);

            $this->protocol->sendUpdateTargetProcess(
                new UpdateTargetProcessMessage(
                    new TargetProcessList(
                        ...array_filter(
                            array_map(
                                fn (int $pid) => $this->process_descriptor_retriever->getProcessDescriptor(
                                    $pid,
                                    $target_php_settings_message->target_php_settings,
                                    $cache,
                                ),
                                $searched_pids
                            ),
                            fn (TargetProcessDescriptor $target_process_descriptor) =>
                                $target_process_descriptor !== TargetProcessDescriptor::getInvalid()
                        )
                    )
                )
            );
            sleep(1);
        }
    }
}
