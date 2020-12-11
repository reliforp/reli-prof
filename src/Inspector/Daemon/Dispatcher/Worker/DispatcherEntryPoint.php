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

namespace PhpProfiler\Inspector\Daemon\Dispatcher\Worker;

use Amp\Loop;
use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Dispatcher\DispatchTable;
use PhpProfiler\Inspector\Daemon\Dispatcher\Protocol\DispatcherWorkerProtocolInterface;
use PhpProfiler\Inspector\Daemon\Dispatcher\Protocol\Message\SettingsMessage;
use PhpProfiler\Inspector\Daemon\Dispatcher\WorkerPool;
use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContextCreator;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Context\PhpSearcherContextCreator;
use PhpProfiler\Lib\Amphp\WorkerEntryPointInterface;
use function Amp\call;

final class DispatcherEntryPoint implements WorkerEntryPointInterface
{
    private PhpSearcherContextCreator $php_searcher_context_creator;
    private PhpReaderContextCreator $php_reader_context_creator;
    private DispatcherWorkerProtocolInterface $protocol;

    public function __construct(
        PhpSearcherContextCreator $php_searcher_context_creator,
        PhpReaderContextCreator $php_reader_context_creator,
        DispatcherWorkerProtocolInterface $protocol
    ) {
        $this->protocol = $protocol;
        $this->php_reader_context_creator = $php_reader_context_creator;
        $this->php_searcher_context_creator = $php_searcher_context_creator;
    }

    public function run(): \Generator
    {
        /** @var SettingsMessage $settings */
        $settings = yield $this->protocol->getSettings();
        $searcher_context = $this->php_searcher_context_creator->create();
        Promise\wait($searcher_context->start());
        Promise\wait($searcher_context->sendTargetRegex($settings->daemon_settings->target_regex));

        $worker_pool = WorkerPool::create(
            $this->php_reader_context_creator,
            $settings->daemon_settings->threads,
            $settings->target_php_settings,
            $settings->trace_loop_settings,
            $settings->get_trace_settings
        );

        $dispatch_table = new DispatchTable(
            $worker_pool,
            $settings->target_php_settings,
            $settings->trace_loop_settings,
            $settings->get_trace_settings
        );

        Loop::run(
        function () use ($searcher_context, $dispatch_table, $worker_pool) {
            $promises = [];
            $promises[] = call(function () use ($searcher_context, $dispatch_table) {
                while (1) {
                    $update_target_message = yield $searcher_context->receivePidList();
                    $dispatch_table->updateTargets($update_target_message->target_process_list);
                }
            });
            foreach ($worker_pool->getWorkers() as $reader) {
                $promises[] = call(
                    function () use ($reader, $dispatch_table) {
                        while (1) {
                            $result = yield $reader->receiveTraceOrDetachWorker();
                            if ($result instanceof TraceMessage) {
                                $this->outputTrace($result);
                            } else {
                                $dispatch_table->releaseOne($result->pid);
                            }
                            file_put_contents('loaded', join("\n", array_reverse(get_included_files())));
                        }
                    }
                );
            }
            yield $promises;
        }
        );
    }

    private function outputTrace(TraceMessage $result): Promise
    {
        return $this->protocol->sendTraces($result);
    }
}