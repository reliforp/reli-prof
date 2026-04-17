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
use Reli\Inspector\Watch\HeapStats;
use Reli\Lib\File\LibcFileReader;
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
            'path to the analysis file (SQLite .db/.sqlite or binary .rmem)'
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
            . ' Default 200000 keeps per-chunk peak under ~80 MB on the wide loadEdgesFfi'
            . ' row layout. The chunked loaders rely on a (run_id, node_id, type) covering'
            . ' index on context_nodes and a (run_id, id) index on context_edges; both'
            . ' are installed lazily on the first report run.',
            '200000',
        );
        $this->addOption(
            'report-workers',
            null,
            InputOption::VALUE_REQUIRED,
            'number of parallel workers for Phase 3 passes. 1 (default) runs sequentially'
            . ' in the parent process. Higher values fork children via pcntl_fork; each'
            . ' child inherits the substrate via copy-on-write and runs a subset of the'
            . ' independent Phase 3 passes in parallel. Requires pcntl; silently falls'
            . ' back to sequential if the extension is missing.',
            '1',
        );
        $this->addOption(
            'mmap-size',
            null,
            InputOption::VALUE_REQUIRED,
            'SQLite mmap_size for the report read connection (and worker connections).'
            . ' Suffix-aware: K / M / G are KiB / MiB / GiB; plain integers are bytes;'
            . ' 0 disables mmap. Bigger means SQLite memory-maps more of the database'
            . ' file instead of paying pread() per page on substrate load. Defaults to'
            . ' 2G, which matches the typical SQLite compile-time cap'
            . ' (SQLITE_MAX_MMAP_SIZE = 0x7fff0000 ≈ 2 GiB - 16 KiB on most distro'
            . ' builds). Asking for more than the cap is harmless — SQLite silently'
            . ' clamps — but the help text would lie about the effective value, so the'
            . ' default is pinned at the realistic ceiling. For DBs larger than 2 GiB,'
            . ' the trailing pages still go through pread + the kernel page cache,'
            . ' which is usually fine if the file is already cache-warm.',
            '2G',
        );
        $this->addOption(
            'prefetch',
            null,
            InputOption::VALUE_NEGATABLE,
            'Hint the kernel to read-ahead the entire DB file into the page cache'
            . ' via posix_fadvise(POSIX_FADV_WILLNEED) before opening it. On Linux'
            . ' this kicks off async read-ahead so SQLite hits a warm cache from the'
            . ' first pread, which is the dominant lever on multi-GB analyze DBs that'
            . ' overflow the SQLite mmap cap (~2 GiB). Silently no-ops on platforms'
            . ' without posix_fadvise (e.g. macOS) or when PHP was built without FFI.'
            . ' Default: on.',
            true,
        );
        $this->addOption(
            'no-derived-cache',
            null,
            InputOption::VALUE_NONE,
            'skip reading and writing the .rmem.derived sidecar cache'
            . ' (subtree sizes, SCC). By default, the cache is read on'
            . ' hit and written on miss to speed up subsequent report runs.',
        );
        $this->addOption(
            'rebuild-derived-cache',
            null,
            InputOption::VALUE_NONE,
            'ignore existing .rmem.derived sidecar and recompute + rewrite it',
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
            $output->writeln("<error>File not found: {$db_file}</error>");
            return 1;
        }

        $format = (string)$input->getOption('output-format');
        /** @var string|null $output_path */
        // @psalm-suppress RedundantCastGivenDocblockType
        $output_path = $input->getOption('output');
        $pretty = (bool)$input->getOption('pretty-print');

        // Detect binary (.rmem) vs SQLite by extension or magic bytes
        $is_binary = $this->isBinaryFormat($db_file);

        $generator = new ReportGenerator();

        if ($is_binary) {
            // Binary path: no SQLite, no PDO, no mmap_size/prefetch/etc.
            /** @var bool|null $ffi_csr */
            $ffi_csr = $input->getOption('ffi-csr');

            // Prefetch the binary file into kernel page cache too
            $prefetch = (bool)$input->getOption('prefetch');
            if ($prefetch) {
                LibcFileReader::prefetchFile($db_file);
            }

            /** @var string $workers_raw */
            $workers_raw = $input->getOption('report-workers');
            $worker_count = (ctype_digit($workers_raw) && (int)$workers_raw >= 1)
                ? (int)$workers_raw : 1;

            $noCache = (bool)$input->getOption('no-derived-cache');
            $rebuildCache = (bool)$input->getOption('rebuild-derived-cache');

            $result = $generator->generateFromBinary(
                $db_file,
                $ffi_csr,
                $worker_count,
                useCache: !$noCache,
                rebuildCache: $rebuildCache,
            );
        } else {
            // SQLite path (original)
            $run_id = (int)$input->getOption('run-id');

            /** @var string $mmap_size_raw */
            $mmap_size_raw = $input->getOption('mmap-size');
            try {
                $mmap_size_bytes = HeapStats::parseSize($mmap_size_raw);
            } catch (\Throwable $e) {
                $output->writeln(sprintf(
                    '<error>Invalid --mmap-size value: %s (use bytes, or K/M/G suffix)</error>',
                    $mmap_size_raw,
                ));
                return 1;
            }
            if ($mmap_size_bytes < 0) {
                $output->writeln(sprintf(
                    '<error>Invalid --mmap-size value: %s (must be >= 0)</error>',
                    $mmap_size_raw,
                ));
                return 1;
            }

            $prefetch = (bool)$input->getOption('prefetch');
            if ($prefetch) {
                LibcFileReader::prefetchFile($db_file);
            }

            $db = new \PDO("sqlite:{$db_file}");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $db->exec('PRAGMA journal_mode = WAL');
            $db->exec('PRAGMA mmap_size = ' . $mmap_size_bytes);

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

            /** @var string $workers_raw */
            $workers_raw = $input->getOption('report-workers');
            if (!ctype_digit($workers_raw) || (int)$workers_raw < 1) {
                $output->writeln(sprintf(
                    '<error>Invalid --report-workers value: %s (must be >= 1)</error>',
                    $workers_raw,
                ));
                return 1;
            }
            $worker_count = (int)$workers_raw;

            $result = $generator->generateFromDb(
                $db,
                $run_id,
                $full_analysis,
                $ffi_csr,
                $link_cache_mode,
                $bulk_fetch_chunk,
                $worker_count,
                $db_file,
                $mmap_size_bytes,
            );
        }

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

    /**
     * Detect binary format by extension (.rmem) or magic bytes ("RMEM").
     */
    private function isBinaryFormat(string $path): bool
    {
        if (str_ends_with($path, '.rmem')) {
            return true;
        }
        // Check magic bytes
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = fread($fh, 4);
        fclose($fh);
        return $magic === 'RMEM';
    }
}
