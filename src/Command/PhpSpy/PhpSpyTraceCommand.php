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

namespace Reli\Command\PhpSpy;

use Reli\Inspector\RetryingLoopProvider;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettingsFromConsoleInput;
use Reli\Inspector\Settings\InspectorSettingsException;
use Reli\Inspector\Settings\PhpSpySettings\PhpSpySettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Lib\Elf\Parser\ElfParserException;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\ProcessSymbolReaderException;
use Reli\Lib\Elf\Tls\TlsFinderException;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\PhpSpy\PhpSpyProcess;
use Reli\Lib\Process\MemoryReader\MemoryReaderException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PhpSpyTraceCommand extends Command
{
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
        private PhpSpyProcess $phpspy_process,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private GetTraceSettingsFromConsoleInput $get_trace_settings_from_console_input,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private PhpSpySettingsFromConsoleInput $phpspy_settings_from_console_input,
        private TargetProcessResolver $target_process_resolver,
        private RetryingLoopProvider $retrying_loop_provider,
        private BinaryAnalysisCache $binary_analysis_cache,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('phpspy:trace')
            ->setDescription(
                'get call traces using phpspy as the tracer backend'
                . ' (reli resolves EG address for ZTS support, then delegates tracing to phpspy)'
            )
        ;
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->get_trace_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->phpspy_settings_from_console_input->setOptions($this);
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'output file path (default: stdout)'
        );
    }

    /**
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws ElfParserException
     * @throws TlsFinderException
     * @throws InspectorSettingsException
     */
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('no-cache')) {
            $this->binary_analysis_cache->disable();
        }

        $get_trace_settings = $this->get_trace_settings_from_console_input->createSettings($input);
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input->createSettings($input);
        $phpspy_settings = $this->phpspy_settings_from_console_input->createSettings($input);

        $process_specifier = $this->target_process_resolver->resolve($target_process_settings);

        $target_php_settings = $this->php_version_detector->decidePhpVersion(
            $process_specifier,
            $target_php_settings
        );

        $output->writeln('<info>resolving EG address...</info>');

        $eg_address = $this->retrying_loop_provider->do(
            try: fn () => $this->php_globals_finder->findExecutorGlobals(
                $process_specifier,
                $target_php_settings
            ),
            retry_on: [\Throwable::class],
            max_retry: 10,
            interval_on_retry_ns: 1000 * 1000 * 10,
        );

        $sg_address = $this->php_globals_finder->findSAPIGlobals(
            $process_specifier,
            $target_php_settings
        );

        $output->writeln(
            '<info>EG address resolved: 0x' . dechex($eg_address) . '</info>'
        );
        $output->writeln(
            '<info>SG address resolved: 0x' . dechex($sg_address) . '</info>'
        );
        $output->writeln(
            '<info>starting phpspy for pid ' . $process_specifier->pid . '...</info>'
        );

        /** @var string|null $output_path */
        $output_path = $input->getOption('output');
        $output_stream = \STDOUT;
        if (is_string($output_path)) {
            $stream = fopen($output_path, 'w');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open output file: ' . $output_path);
            }
            $output_stream = $stream;
        }

        $this->phpspy_process->start(
            $process_specifier->pid,
            $eg_address,
            $sg_address,
            $get_trace_settings->depth,
            $phpspy_settings,
            $output_stream,
        );

        // Main loop: passthrough phpspy output until it exits or we get interrupted
        $interrupted = false;
        pcntl_signal(SIGINT, function () use (&$interrupted) {
            $interrupted = true;
        });
        pcntl_signal(SIGTERM, function () use (&$interrupted) {
            $interrupted = true;
        });

        while ($this->phpspy_process->isRunning() && !$interrupted) {
            $this->phpspy_process->passthrough($output_stream);
            pcntl_signal_dispatch();
            usleep(1000); // 1ms poll interval
        }

        // Drain remaining output
        $this->phpspy_process->passthrough($output_stream);
        $this->phpspy_process->stop();

        if ($output_stream !== \STDOUT) {
            fclose($output_stream);
        }

        return 0;
    }
}
