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
use Amp\Success;
use PhpProfiler\Inspector\Daemon\Dispatcher\Context\DispatcherContextCreator;
use PhpProfiler\Inspector\Daemon\Gui\Gui;
use PhpProfiler\Inspector\Daemon\Reader\Protocol\Message\TraceMessage;
use PhpProfiler\Inspector\Settings\DaemonSettings\DaemonSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettingsFromConsoleInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Amp\call;

final class DaemonCommand extends Command
{
    private DispatcherContextCreator $dispatcher_context_creator;
    private TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input;
    private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input;
    private GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input;
    private DaemonSettingsFromConsoleInput $daemon_settings_from_console_input;

    public function __construct(
        DispatcherContextCreator $dispatcher_context_creator,
        DaemonSettingsFromConsoleInput $daemon_settings_from_console_input,
        GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input,
        TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        TraceLoopSettingsFromConsoleInput $trace_loop_settings_from_console_input
    ) {
        $this->daemon_settings_from_console_input = $daemon_settings_from_console_input;
        $this->get_trace_settings_from_console_input = $get_trace_settings_from_console_input;
        $this->target_php_settings_from_console_input = $target_php_settings_from_console_input;
        $this->trace_loop_settings_from_console_input = $trace_loop_settings_from_console_input;
        $this->dispatcher_context_creator = $dispatcher_context_creator;
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
        $gui = Gui::load();
        $gui->build();
        $gui->run();

        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $daemon_settings = $this->daemon_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $loop_settings = $this->trace_loop_settings_from_console_input->createSettings($input);

        $dispatcher_context = $this->dispatcher_context_creator->create();
        Promise\wait($dispatcher_context->start());

        Promise\wait($dispatcher_context->sendSettings(
            $get_trace_settings,
            $daemon_settings,
            $target_php_settings,
            $loop_settings
        ));

        echo join("\n", get_included_files());

        return 0;
    }

    private function outputTrace(OutputInterface $output, TraceMessage $message): void
    {
        $numbers = range(0, count($message->trace) - 1);
        $output->writeln(
            join(
                PHP_EOL,
                array_map(
                    fn (string $trace_line, $number) => $number . ' ' . $trace_line,
                    $message->trace,
                    $numbers
                )
            ) . PHP_EOL
        );
    }
}
