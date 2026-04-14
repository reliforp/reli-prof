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

use Reli\Inspector\Output\MemoryOutput\Comparison\BinaryComparisonDataProvider;
use Reli\Inspector\Output\MemoryOutput\Comparison\ComparisonDataProvider;
use Reli\Inspector\Output\MemoryOutput\Comparison\ComparisonGenerator;
use Reli\Inspector\Output\MemoryOutput\Comparison\Formatter\JsonComparisonFormatter;
use Reli\Inspector\Output\MemoryOutput\Comparison\Formatter\TextComparisonFormatter;
use Reli\Inspector\Output\MemoryOutput\Comparison\PdoComparisonDataProvider;
use Reli\Lib\Log\Log;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MemoryCompareCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:compare')
            ->setDescription('[experimental] compare two memory snapshot SQLite databases')
        ;
        $this->addArgument(
            'baseline',
            InputArgument::REQUIRED,
            'path to the baseline SQLite database file'
        );
        $this->addArgument(
            'target',
            InputArgument::OPTIONAL,
            'path to the target SQLite database file (omit to compare run IDs within the same file)'
        );
        $this->addOption(
            'run-id-baseline',
            null,
            InputOption::VALUE_REQUIRED,
            'run ID for baseline (default: 1)',
            '1',
        );
        $this->addOption(
            'run-id-target',
            null,
            InputOption::VALUE_REQUIRED,
            'run ID for target (default: 1)',
            '1',
        );
        $this->addOption(
            'output-format',
            'f',
            InputOption::VALUE_REQUIRED,
            'output format: report (text) or report-json',
            'report',
        );
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'output file path (default: stdout)',
        );
        $this->addOption(
            'pretty-print',
            null,
            InputOption::VALUE_NEGATABLE,
            'pretty print JSON output (default: on)',
            true,
        );
        $this->addOption(
            'threshold',
            null,
            InputOption::VALUE_REQUIRED,
            'minimum change percentage to report (default: 0)',
            '0',
        );
        $this->addOption(
            'full-analysis',
            null,
            InputOption::VALUE_NEGATABLE,
            'run all analysis passes for both snapshots (default: off)',
            false,
        );
        $this->addOption(
            'memory-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'set PHP memory_limit for analysis (e.g. 2G, 512M)',
        );
        $this->addOption(
            'ffi-csr',
            null,
            InputOption::VALUE_NEGATABLE,
            'force FFI CSR graph substrate (default: auto)',
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

        Log::info('start memory:compare command');

        $baseline_file = (string)$input->getArgument('baseline');
        /** @var string|null $target_arg */
        $target_arg = $input->getArgument('target');
        $target_file = $target_arg !== null ? $target_arg : $baseline_file;

        if (!file_exists($baseline_file)) {
            $output->writeln("<error>Baseline database not found: {$baseline_file}</error>");
            return 1;
        }
        if ($target_file !== $baseline_file && !file_exists($target_file)) {
            $output->writeln("<error>Target database not found: {$target_file}</error>");
            return 1;
        }

        $baseline_run_id = (int)$input->getOption('run-id-baseline');
        $target_run_id = (int)$input->getOption('run-id-target');
        $format = (string)$input->getOption('output-format');
        /** @var string|null $output_path */
        $output_path = $input->getOption('output');
        $pretty = (bool)$input->getOption('pretty-print');
        $threshold = (float)$input->getOption('threshold');
        $full_analysis = (bool)$input->getOption('full-analysis');
        /** @var bool|null $ffi_csr */
        $ffi_csr = $input->getOption('ffi-csr');

        $baseline_provider = $this->createProvider($baseline_file, $baseline_run_id);
        $target_provider = $this->createProvider($target_file, $target_run_id);

        $generator = new ComparisonGenerator();
        $result = $generator->compare(
            $baseline_provider,
            $target_provider,
            $threshold,
            $full_analysis,
            $ffi_csr,
        );

        $formatter = match ($format) {
            'report' => new TextComparisonFormatter(),
            'report-json' => new JsonComparisonFormatter($pretty),
            default => throw new \RuntimeException(
                "Unsupported format: {$format} (supported: report, report-json)"
            ),
        };

        $formatted = $formatter->format($result);

        if ($output_path !== null) {
            file_put_contents($output_path, $formatted);
            $output->writeln("<info>Comparison report written to {$output_path}</info>");
        } else {
            $output->write($formatted);
        }

        Log::info('end memory:compare command');
        return 0;
    }

    private function createProvider(string $path, int $run_id): ComparisonDataProvider
    {
        if (str_ends_with($path, '.rmem') || $this->isBinaryFile($path)) {
            return new BinaryComparisonDataProvider($path);
        }
        $db = new \PDO("sqlite:{$path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA mmap_size = 268435456');
        return new PdoComparisonDataProvider($db, $run_id);
    }

    private function isBinaryFile(string $path): bool
    {
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return false;
        }
        $magic = fread($fp, 4);
        fclose($fp);
        return $magic === 'RMEM';
    }
}
