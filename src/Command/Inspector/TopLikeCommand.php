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

namespace Reli\Command\Inspector;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Reli\Inspector\Daemon\Dispatcher\DispatchTable;
use Reli\Inspector\Daemon\Dispatcher\WorkerPool;
use Reli\Inspector\Daemon\Reader\Context\PhpReaderContextCreator;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Searcher\Context\PhpSearcherContextCreator;
use Reli\Inspector\Output\TopLike\TopLikeFormatter;
use Reli\Inspector\Output\TopLike\TopLikeFormatterFactory;
use Reli\Inspector\Settings\DaemonSettings\DaemonSettingsFromConsoleInput;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use Reli\Lib\Console\EchoBackCanceller;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Amp\async;
use function Amp\Future\await;

final class TopLikeCommand extends Command
{
    public function __construct(
        private PhpSearcherContextCreator $php_searcher_context_creator,
        private PhpReaderContextCreator $php_reader_context_creator,
        private DaemonSettingsFromConsoleInput $daemon_settings_from_console_input,
        private GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input,
        private TopLikeFormatterFactory $formatter_factory,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('inspector:top')
            ->setDescription(
                'show an aggregated view of traces in real time in a form similar to the UNIX top command.'
            )
        ;
        $this->daemon_settings_from_console_input->setOptions($this);
        $this->get_trace_settings_from_console_input->setOptions($this);
        $this->trace_loop_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $daemon_settings = $this->daemon_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);
        $formatter = $this->formatter_factory->create(
            $daemon_settings->target_regex,
            $output
        );

        $searcher_context = $this->php_searcher_context_creator->create();
        $searcher_context->start();
        $searcher_context->sendTarget(
            $daemon_settings->target_regex,
            $target_php_settings,
            getmypid(),
        );

        $worker_pool = WorkerPool::create(
            $this->php_reader_context_creator,
            $daemon_settings->threads,
            $loop_settings,
            $get_trace_settings
        );

        $dispatch_table = new DispatchTable(
            $worker_pool,
        );

        $_echo_back_canceler = new EchoBackCanceller();

        $cancellation = new DeferredCancellation();

        EventLoop::onReadable(
            STDIN,
            /** @param resource $stream */
            function (string $watcher_id, $stream) use ($cancellation) {
                $key = fread($stream, 1);
                if ($key === 'q') {
                    EventLoop::cancel($watcher_id);
                    $cancellation->cancel();
                }
            }
        );
        $futures = [];
        $futures[] = async(function () use ($searcher_context, $dispatch_table) {
            while (1) {
                Log::debug('receiving pid List');
                $update_target_message = $searcher_context->receivePidList();
                Log::debug('update targets', [
                    'update' => $update_target_message->target_process_list->getArray(),
                    'current' => $dispatch_table->worker_pool->debugDump(),
                ]);
                $dispatch_table->updateTargets($update_target_message->target_process_list);
                Log::debug('target updated', [$dispatch_table->worker_pool->debugDump()]);
            }
        });
        foreach ($worker_pool->getWorkers() as $reader) {
            $futures[] = async(
                function () use ($reader, $dispatch_table, $formatter) {
                    while (1) {
                        $result = $reader->receiveTraceOrDetachWorker();
                        if ($result instanceof TraceMessage) {
                            $this->outputTrace($formatter, $result);
                        } else {
                            Log::debug('releaseOne', [$result]);
                            $dispatch_table->releaseOne($result->pid);
                            $this->outputTrace($formatter, new TraceMessage(
                                new CallTrace()
                            ));
                        }
                    }
                }
            );
        }

        try {
            await($futures, $cancellation->getCancellation());
        } catch (CancelledException $e) {
            Log::debug('cancelled', ['exception' => $e->getMessage()]);
        }

        return 0;
    }

    private function outputTrace(
        TopLikeFormatter $formatter,
        TraceMessage $message
    ): void {
        $formatter->format($message->trace);
    }
}
