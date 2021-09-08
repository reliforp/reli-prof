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
use PhpProfiler\Inspector\Output\TraceFormatter\CallTraceFormatter;
use PhpProfiler\Inspector\Output\TraceFormatter\Templated\TemplatedTraceFormatterFactory;
use PhpProfiler\Inspector\Settings\DaemonSettings\DaemonSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TemplatedTraceFormatterSettings\TemplateSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use PhpProfiler\Lib\Console\EchoBackCanceller;
use PhpProfiler\Lib\Log\Log;
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
        private TemplateSettingsFromConsoleInput $template_settings_from_console_input,
        private TemplatedTraceFormatterFactory $templated_trace_formatter_factory,
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
        $this->template_settings_from_console_input->setOptions($this);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $daemon_settings = $this->daemon_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);
        $template_settings = $this->template_settings_from_console_input->createSettings($input);
        $formatter = $this->templated_trace_formatter_factory->createFromSettings($template_settings);

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
        Loop::run(function () use ($dispatch_table, $searcher_context, $worker_pool, $output, $formatter) {
            $promises = [];
            $promises[] = call(function () use ($searcher_context, $dispatch_table) {
                while (1) {
                    Log::debug('receiving pid List');
                    $update_target_message = yield $searcher_context->receivePidList();
                    Log::debug('update targets', [
                        'update' => $update_target_message->target_process_list->getArray(),
                        'current' => $dispatch_table->worker_pool->debugDump(),
                    ]);
                    $dispatch_table->updateTargets($update_target_message->target_process_list);
                    Log::debug('target updated', [$dispatch_table->worker_pool->debugDump()]);
                }
            });
            foreach ($worker_pool->getWorkers() as $reader) {
                $promises[] = call(
                    function () use ($reader, $dispatch_table, $output, $formatter) {
                        while (1) {
                            $result = yield $reader->receiveTraceOrDetachWorker();
                            if ($result instanceof TraceMessage) {
                                $this->outputTrace($output, $formatter, $result);
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

    private function outputTrace(
        OutputInterface $output,
        CallTraceFormatter $formatter,
        TraceMessage $message
    ): void {
        $output->write(
            $formatter->format($message->trace) . PHP_EOL
        );
    }
}
