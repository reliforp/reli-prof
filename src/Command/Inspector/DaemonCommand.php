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

use Amp\Loop;
use Amp\Promise;
use Reli\Inspector\Daemon\Dispatcher\DispatchTable;
use Reli\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use Reli\Inspector\Daemon\Dispatcher\WorkerPool;
use Reli\Inspector\Daemon\Reader\Context\PhpReaderContextCreator;
use Reli\Inspector\Daemon\Searcher\Context\PhpSearcherContextCreator;
use Reli\Inspector\Output\TraceOutput\TraceOutputFactory;
use Reli\Inspector\Settings\DaemonSettings\DaemonSettingsFromConsoleInput;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use Reli\Inspector\Settings\OutputSettings\OutputSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use Reli\Lib\Console\EchoBackCanceller;
use Reli\Lib\Log\Log;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Amp\call;
use function fread;

use const STDIN;

final class DaemonCommand extends Command
{
    public function __construct(
        private PhpSearcherContextCreator $php_searcher_context_creator,
        private PhpReaderContextCreator $php_reader_context_creator,
        private DaemonSettingsFromConsoleInput $daemon_settings_from_console_input,
        private GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input,
        private OutputSettingsFromConsoleInput $output_settings_from_console_input,
        private TraceOutputFactory $trace_output_factory,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('inspector:daemon')
            ->setDescription('concurrently get call traces from processes whose command-lines match a given regex')
        ;
        $this->daemon_settings_from_console_input->setOptions($this);
        $this->get_trace_settings_from_console_input->setOptions($this);
        $this->trace_loop_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->output_settings_from_console_input->setOptions($this);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $daemon_settings = $this->daemon_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);
        $trace_output = $this->trace_output_factory->fromSettingsAndConsoleOutput(
            $output,
            $this->output_settings_from_console_input->createSettings($input),
        );

        $searcher_context = $this->php_searcher_context_creator->create();
        Promise\wait($searcher_context->start());
        Promise\wait(
            $searcher_context->sendTarget(
                $daemon_settings->target_regex,
                $target_php_settings,
            )
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

        Loop::onReadable(
            STDIN,
            /** @param resource $stream */
            function (string $watcher_id, $stream) {
                $key = fread($stream, 1);
                if ($key === 'q') {
                    Loop::cancel($watcher_id);
                    Loop::stop();
                }
            }
        );
        Loop::run(function () use ($dispatch_table, $searcher_context, $worker_pool, $trace_output) {
            $promises = [];
            $promises[] = call(function () use ($searcher_context, $dispatch_table) {
                while (1) {
                    Log::debug('receiving pid List');
                    $update_target_message = yield $searcher_context->receivePidList();
                    Log::debug('update targets', [
                        'update' => $update_target_message->target_process_list->getArray(),
                        'current' => $dispatch_table->worker_pool->debugDump(),
                    ]);
                    yield from $dispatch_table->updateTargets($update_target_message->target_process_list);
                    Log::debug('target updated', [$dispatch_table->worker_pool->debugDump()]);
                }
            });
            foreach ($worker_pool->getWorkers() as $reader) {
                $promises[] = call(
                    function () use ($reader, $dispatch_table, $trace_output) {
                        while (1) {
                            $result = yield $reader->receiveTraceOrDetachWorker();
                            if ($result instanceof TraceMessage) {
                                $trace_output->output($result->trace);
                            } else {
                                Log::debug('releaseOne', [$result]);
                                $dispatch_table->releaseOne($result->pid);
                            }
                        }
                    }
                );
            }
            yield $promises;
        });

        return 0;
    }
}
