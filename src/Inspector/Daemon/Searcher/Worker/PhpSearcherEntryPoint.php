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

use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessList;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\PhpSearcherWorkerProtocolInterface;
use PhpProfiler\Lib\Amphp\WorkerEntryPointInterface;
use PhpProfiler\Lib\Process\Search\ProcessSearcherInterface;

use function sleep;

final class PhpSearcherEntryPoint implements WorkerEntryPointInterface
{
    public function __construct(
        private PhpSearcherWorkerProtocolInterface $protocol,
        private ProcessSearcherInterface $process_searcher,
        private ProcessDescriptorRetriever $process_descriptor_retriever,
    ) {
    }

    public function run(): \Generator
    {
        /**
         * @psalm-ignore-var
         * @var TargetPhpSettingsMessage $target_php_settings_message
         */
        $target_php_settings_message = yield $this->protocol->receiveTargetPhpSettings();
        $cache = new ProcessDescriptorCache();

        while (1) {
            $searched_pids = $this->process_searcher->searchByRegex(
                $target_php_settings_message->regex
            );
            $cache->removeDisappeared(...$searched_pids);

            yield $this->protocol->sendUpdateTargetProcess(
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
