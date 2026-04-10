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

use Reli\Inspector\Output\MemoryOutput\Report\Formatter\JsonReportFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\Formatter\TextReportFormatter;
use Reli\Inspector\Output\MemoryOutput\Report\ReportGenerator;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\LinkCacheMode;
use Reli\Lib\Log\Log;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MemoryReportCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:report')
            ->setDescription('[experimental] generate analysis report from a memory snapshot SQLite database')
        ;
        $this->addArgument(
            'db-file',
            InputArgument::REQUIRED,
            'path to the SQLite database file (created by inspector:memory with -f sqlite3)'
        );
        $this->addOption(
            'run-id',
            null,
            InputOption::VALUE_REQUIRED,
            'run ID to analyze (default: 1)',
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
            'full-analysis',
            null,
            InputOption::VALUE_NEGATABLE,
            'run all analysis passes (default: on; --no-full-analysis to limit for very large snapshots)',
            true,
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
            'force FFI CSR graph substrate (default: auto; --ffi-csr to force on, --no-ffi-csr to force off)',
        );
        $this->addOption(
            'link-cache',
            null,
            InputOption::VALUE_REQUIRED,
            'tree-edge link cache strategy: auto (default; bulk-read small graphs, lazy on huge ones),'
            . ' eager (always bulk-read; faster, more memory),'
            . ' lazy (per-edge with bounded cache; slower, flat memory)',
            'auto',
        );
        $this->addOption(
            'substrate-bulk-fetch-chunk',
            null,
            InputOption::VALUE_REQUIRED,
            'rows per chunked fetchAll when loading the substrate from SQLite.'
            . ' Larger values trade memory for speed (fewer PHP/PDO round trips).'
            . ' 0 means "no chunking, single fetchAll" (max speed, max memory).'
            . ' Default 200000 keeps per-chunk peak under ~80 MB.',
            '200000',
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

        Log::info('start memory:report command');

        $db_file = (string)$input->getArgument('db-file');
        if (!file_exists($db_file)) {
            $output->writeln("<error>Database file not found: {$db_file}</error>");
            return 1;
        }

        $run_id = (int)$input->getOption('run-id');
        $format = (string)$input->getOption('output-format');
        /** @var string|null $output_path */
        // @psalm-suppress RedundantCastGivenDocblockType
        $output_path = $input->getOption('output');
        $pretty = (bool)$input->getOption('pretty-print');

        $db = new \PDO("sqlite:{$db_file}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA mmap_size = 268435456');

        $full_analysis = (bool)$input->getOption('full-analysis');
        /** @var bool|null $ffi_csr */
        $ffi_csr = $input->getOption('ffi-csr');

        /** @var string $link_cache_raw */
        $link_cache_raw = $input->getOption('link-cache');
        $link_cache_mode = LinkCacheMode::tryFrom($link_cache_raw);
        if ($link_cache_mode === null) {
            $output->writeln(sprintf(
                '<error>Unsupported --link-cache value: %s (supported: auto, eager, lazy)</error>',
                $link_cache_raw,
            ));
            return 1;
        }

        /** @var string $bulk_fetch_chunk_raw */
        $bulk_fetch_chunk_raw = $input->getOption('substrate-bulk-fetch-chunk');
        if (!ctype_digit($bulk_fetch_chunk_raw)) {
            $output->writeln(sprintf(
                '<error>Invalid --substrate-bulk-fetch-chunk value: %s (must be a non-negative integer)</error>',
                $bulk_fetch_chunk_raw,
            ));
            return 1;
        }
        $bulk_fetch_chunk = (int)$bulk_fetch_chunk_raw;

        $generator = new ReportGenerator();
        $result = $generator->generateFromDb(
            $db,
            $run_id,
            $full_analysis,
            $ffi_csr,
            $link_cache_mode,
            $bulk_fetch_chunk,
        );

        $formatter = match ($format) {
            'report' => new TextReportFormatter(),
            'report-json' => new JsonReportFormatter($pretty),
            default => throw new \RuntimeException(
                "Unsupported format: {$format} (supported: report, report-json)"
            ),
        };

        $formatted = $formatter->format($result);

        if ($output_path !== null) {
            file_put_contents($output_path, $formatted);
            $output->writeln("<info>Report written to {$output_path}</info>");
        } else {
            $output->write($formatted);
        }

        Log::info('end memory:report command');
        return 0;
    }
}
