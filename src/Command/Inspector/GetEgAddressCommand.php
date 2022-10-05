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

namespace PhpProfiler\Command\Inspector;

use PhpProfiler\Inspector\RetryingLoopProvider;
use PhpProfiler\Inspector\Settings\InspectorSettingsException;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use PhpProfiler\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use PhpProfiler\Inspector\TargetProcess\TargetProcessResolver;
use PhpProfiler\Lib\Elf\Parser\ElfParserException;
use PhpProfiler\Lib\Elf\Tls\TlsFinderException;
use PhpProfiler\Lib\PhpProcessReader\PhpGlobalsFinder;
use PhpProfiler\Lib\Process\MemoryReader\MemoryReaderException;
use PhpProfiler\Lib\Elf\Process\ProcessSymbolReaderException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function dechex;
use function sprintf;

final class GetEgAddressCommand extends Command
{
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private TargetProcessResolver $target_process_resolver,
        private RetryingLoopProvider $retrying_loop_provider,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('inspector:eg_address')
            ->setDescription('get EG address from an outer process or thread')
        ;
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
    }

    /**
     * @throws MemoryReaderException
     * @throws ProcessSymbolReaderException
     * @throws ElfParserException
     * @throws TlsFinderException
     * @throws InspectorSettingsException
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input->createSettings($input);

        $process_specifier = $this->target_process_resolver->resolve($target_process_settings);

        // see the comment at GetTraceCommand::execute()
        $eg_address = $this->retrying_loop_provider->do(
            try: fn () => $this->php_globals_finder->findExecutorGlobals(
                $process_specifier,
                $target_php_settings
            ),
            retry_on: [\Throwable::class],
            max_retry: 10,
            interval_on_retry_ns: 1000 * 1000 * 10,
        );

        $output->writeln(
            sprintf(
                '0x%s',
                dechex($eg_address)
            )
        );

        return 0;
    }
}
