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

namespace PhpProfiler\Command\Inspector;

use Amp\Loop;
use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Dispatcher\DispatchTable;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Daemon\Dispatcher\WorkerPool;
use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContextCreator;
use PhpProfiler\Inspector\Daemon\Searcher\Context\PhpSearcherContextCreator;
use PhpProfiler\Inspector\Settings\DaemonSettings\DaemonSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use PhpProfiler\Lib\Console\EchoBackCanceller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Amp\call;

final class DaemonCommand extends Command
{
    private PhpSearcherContextCreator $php_searcher_context_creator;
    private PhpReaderContextCreator $php_reader_context_creator;
    private DaemonSettingsFromConsoleInput $daemon_settings_from_console_input;
    private GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input;
    private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input;
    private TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input;

    public function __construct(
        PhpSearcherContextCreator $php_searcher_context_creator,
        PhpReaderContextCreator $php_reader_context_creator,
        DaemonSettingsFromConsoleInput $daemon_settings_from_console_input,
        GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input,
        TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input
    ) {
        $this->php_reader_context_creator = $php_reader_context_creator;
        $this->php_searcher_context_creator = $php_searcher_context_creator;
        $this->daemon_settings_from_console_input = $daemon_settings_from_console_input;
        $this->get_trace_settings_from_console_input = $get_trace_settings_from_console_input;
        $this->target_php_settings_from_console_input = $target_php_settings_from_console_input;
        $this->trace_loop_settings_from_console_input = $trace_loop_settings_from_console_input;
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
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $daemon_settings = $this->daemon_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);

        $searcher_context = $this->php_searcher_context_creator->create();
        Promise\wait($searcher_context->start());
        Promise\wait($searcher_context->sendTargetRegex($daemon_settings->target_regex));

        $worker_pool = WorkerPool::create(
            $this->php_reader_context_creator,
            $daemon_settings->threads,
            $target_php_settings,
            $loop_settings,
            $get_trace_settings
        );

        $dispatch_table = new DispatchTable(
            $worker_pool,
            $target_php_settings,
            $loop_settings,
            $get_trace_settings
        );

        $echo_back_canceler = new EchoBackCanceller();

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
        Loop::run(function () use ($dispatch_table, $searcher_context, $worker_pool, $output) {
            $promises = [];
            $promises[] = call(function () use ($searcher_context, $dispatch_table) {
                while (1) {
                    $update_target_message = yield $searcher_context->receivePidList();
                    $dispatch_table->updateTargets($update_target_message->target_process_list);
                }
            });
            foreach ($worker_pool->getWorkers() as $reader) {
                $promises[] = call(
                    function () use ($reader, $dispatch_table, $output) {
                        while (1) {
                            $result = yield $reader->receiveTraceOrDetachWorker();
                            if ($result instanceof TraceMessage) {
                                $this->outputTrace($output, $result);
                            } else {
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

    private function outputTrace(OutputInterface $output, TraceMessage $message): void
    {
        $output->writeln(
            join(PHP_EOL, $message->trace) . PHP_EOL
        );
    }
}
