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

use PhpCast\NullableCast;
use Reli\Inspector\MemoryDump\MemoryDumpReaderFactory;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryProfilerSettingsFromConsoleInput;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Log\Log;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MemoryAnalyzeCommand extends Command
{
    public function __construct(
        private MemoryProfilerSettingsFromConsoleInput $memory_profiler_settings_from_console_input,
        private MemoryDumpReaderFactory $memory_dump_reader_factory,
        private BinaryAnalysisCache $binary_analysis_cache,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:analyze')
            ->setDescription('[experimental] analyze a memory dump file created by inspector:memory:dump')
        ;
        $this->memory_profiler_settings_from_console_input->setOptions($this);
        $this->addArgument(
            'dump-file',
            InputArgument::REQUIRED,
            'path to the memory dump file'
        );
        $this->addOption(
            'dependency-root',
            'r',
            InputOption::VALUE_REQUIRED,
            'dependency root directory (for read-only segment fallback)'
        );
        $this->addOption('no-cache', null, InputOption::VALUE_NONE, 'disable the binary analysis cache');
        $this->addOption(
            'memory-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'set PHP memory_limit for analysis (e.g. 2G, 512M)',
        );
        $this->addOption(
            'read-buffer',
            null,
            InputOption::VALUE_REQUIRED,
            'read-ahead buffer size for dump file reads (e.g. 256K, 1M, 0 to disable)',
            '256K',
        );
        $this->addOption(
            'no-fast-path',
            null,
            InputOption::VALUE_NONE,
            'disable the offset-table fast path (use FFI path for all reads)',
        );
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $memory_limit */
        $memory_limit = $input->getOption('memory-limit');
        if (is_string($memory_limit) && $memory_limit !== '') {
            ini_set('memory_limit', $memory_limit);
        }
        if ($input->getOption('no-cache')) {
            $this->binary_analysis_cache->disable();
        }
        Log::info('start memory:analyze command');

        $memory_profiler_settings = $this->memory_profiler_settings_from_console_input->createSettings($input);

        $dump_file = NullableCast::toString($input->getArgument('dump-file'));
        if ($dump_file === null) {
            throw new \RuntimeException('dump-file is not specified');
        }

        $path_mapping = [];
        $dependency_root = NullableCast::toString($input->getOption('dependency-root'));
        if ($dependency_root !== null) {
            $path_mapping['/'] = $dependency_root;
        }

        $read_buffer_size = self::parseByteSize((string) $input->getOption('read-buffer'));
        $no_fast_path = (bool) $input->getOption('no-fast-path');
        $dump_reader = $this->memory_dump_reader_factory->createFromPath(
            $dump_file,
            $path_mapping,
            $read_buffer_size,
            $no_fast_path,
        );
        $dump_reader->read($memory_profiler_settings);

        Log::info('end memory:analyze command');
        return 0;
    }

    private static function parseByteSize(string $value): int
    {
        $value = trim($value);
        if ($value === '0' || $value === '') {
            return 0;
        }
        $last = strtoupper(substr($value, -1));
        $num = (int) $value;
        return match ($last) {
            'K' => $num * 1024,
            'M' => $num * 1024 * 1024,
            'G' => $num * 1024 * 1024 * 1024,
            default => $num,
        };
    }
}
