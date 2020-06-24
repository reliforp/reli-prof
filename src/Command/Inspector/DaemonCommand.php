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
use PhpProfiler\Inspector\Daemon\Dispatcher\WorkerPool;
use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContext;
use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContextCreator;
use PhpProfiler\Inspector\Daemon\Searcher\Context\PhpSearcherContextCreator;
use PhpProfiler\Inspector\Settings\DaemonSettings;
use PhpProfiler\Inspector\Settings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DaemonCommand extends Command
{
    private PhpSearcherContextCreator $php_searcher_context_creator;
    private PhpReaderContextCreator $php_reader_context_creator;

    public function __construct(
        PhpSearcherContextCreator $php_searcher_context_creator,
        PhpReaderContextCreator $php_reader_context_creator
    ) {
        parent::__construct();
        $this->php_reader_context_creator = $php_reader_context_creator;
        $this->php_searcher_context_creator = $php_searcher_context_creator;
    }

    public function configure(): void
    {
        $this->setName('inspector:daemon')
            ->setDescription('periodically get running function name from an outer process or thread')
            ->addOption(
                'target-regex',
                'P',
                InputOption::VALUE_OPTIONAL,
                'regex to find the php binary loaded in the target process'
            )
            ->addOption('depth', 'd', InputOption::VALUE_OPTIONAL, 'max depth')
            ->addOption(
                'sleep-ns',
                's',
                InputOption::VALUE_OPTIONAL,
                'nanoseconds between traces (default: 1000 * 1000 * 10)'
            )
            ->addOption(
                'max-retries',
                'r',
                InputOption::VALUE_OPTIONAL,
                'max retries on contiguous errors of read (default: 10)'
            )
            ->addOption(
                'threads',
                'T',
                InputOption::VALUE_OPTIONAL,
                'number of workers (default: 8)'
            )
            ->addOption(
                'php-regex',
                null,
                InputOption::VALUE_OPTIONAL,
                'regex to find the php binary loaded in the target process'
            )
            ->addOption(
                'libpthread-regex',
                null,
                InputOption::VALUE_OPTIONAL,
                'regex to find the libpthread.so loaded in the target process'
            )
            ->addOption(
                'php-version',
                null,
                InputOption::VALUE_OPTIONAL,
                'php version of the target'
            )
            ->addOption(
                'php-path',
                null,
                InputOption::VALUE_OPTIONAL,
                'path to the php binary (only needed in tracing chrooted ZTS target)'
            )
            ->addOption(
                'libpthread-path',
                null,
                InputOption::VALUE_OPTIONAL,
                'path to the libpthread.so (only needed in tracing chrooted ZTS target)'
            )
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $target_php_settings = TargetPhpSettings::fromConsoleInput($input);
        $loop_settings = TraceLoopSettings::fromConsoleInput($input);
        $get_trace_settings = GetTraceSettings::fromConsoleInput($input);
        $daemon_settings = DaemonSettings::fromConsoleInput($input);

        $searcher_context = $this->php_searcher_context_creator->create();
        Promise\wait($searcher_context->start());
        Promise\wait($searcher_context->sendTargetRegex($daemon_settings->target_regex));
        $pid_list = Promise\wait($searcher_context->receivePidList());

        $worker_pool = WorkerPool::create($this->php_reader_context_creator, $daemon_settings->threads);

        $readers = [];
        foreach ($pid_list as $target_pid) {
            $reader_context = $worker_pool->getFreeWorker();
            if (is_null($reader_context)) {
                continue;
            }
            Promise\wait(
                $reader_context->sendSettings(
                    [
                        $target_pid,
                        $target_php_settings,
                        $loop_settings,
                        $get_trace_settings
                    ]
                )
            );
            $readers[$target_pid] = $reader_context;
        }
        exec('stty -icanon -echo');

        Loop::run(function () use (&$readers, $output) {
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
            Loop::repeat(10, function () use (&$readers, $output) {
                /** @var array<int, PhpReaderContext> $readers */

                $promises = [];
                foreach ($readers as $pid => $reader) {
                    if (!$reader->isRunning()) {
                        /** @psalm-suppress MixedArrayAccess*/
                        unset($readers[$pid]);
                        continue;
                    }
                    $promises[] = \Amp\call(
                        function () use ($reader, &$readers, $pid, $output) {
                            /** @psalm-suppress MixedArrayAccess*/
                            unset($readers[$pid]);
                            /** @var string $result */
                            $result = yield $reader->receiveTrace();
                            $output->write($result);
                            $readers[$pid] = $reader;
                        }
                    );
                }
                yield $promises;
            });
        });

        return 0;
    }
}
