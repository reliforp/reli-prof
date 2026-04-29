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
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MemoryReportCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:report')
            ->setDescription('generate analysis report from a memory snapshot (.rmem or SQLite .db/.sqlite)')
        ;
        $this->addArgument(
            'db-file',
            InputArgument::REQUIRED,
            'path to the analysis file (SQLite .db/.sqlite or rmem .rmem)'
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
        // Advanced / tuning options.
        // These mostly matter on large snapshots (multi-GB SQLite, OOM, slow
        // substrate load). The help text below is a one-liner each; the
        // background, defaults' rationale, and "which knob to reach for first"
        // live in docs/internals/memory-report-tuning.md.
        $this->addOption(
            'ffi-csr',
            null,
            InputOption::VALUE_NEGATABLE,
            'force FFI CSR graph substrate on/off (default: auto)',
        );
        $this->addOption(
            'link-cache',
            null,
            InputOption::VALUE_REQUIRED,
            'tree-edge link cache: auto | eager | lazy',
            'auto',
        );
        $this->addOption(
            'substrate-bulk-fetch-chunk',
            null,
            InputOption::VALUE_REQUIRED,
            'rows per chunked fetchAll when loading the SQLite substrate',
            '200000',
        );
        $this->addOption(
            'report-workers',
            null,
            InputOption::VALUE_REQUIRED,
            'parallel workers for Phase 3 passes'
            . ' (forks via pcntl_fork; falls back to sequential without ext-pcntl)',
            '1',
        );
        $this->addOption(
            'mmap-size',
            null,
            InputOption::VALUE_REQUIRED,
            'SQLite mmap_size for the read connection; suffix-aware (K/M/G), 0 disables',
            '2G',
        );
        $this->addOption(
            'prefetch',
            null,
            InputOption::VALUE_NEGATABLE,
            'posix_fadvise(POSIX_FADV_WILLNEED) the DB file before opening (default: on)',
            true,
        );
        $this->addOption(
            'no-derived-cache',
            null,
            InputOption::VALUE_NONE,
            'skip the .rmem.derived sidecar cache (subtree sizes, SCC)',
        );
        $this->addOption(
            'rebuild-derived-cache',
            null,
            InputOption::VALUE_NONE,
            'ignore existing .rmem.derived sidecar and recompute + rewrite it',
        );

        // Mention the tuning doc once as part of the command help so users
        // who hit `--help` for tuning advice know where to look.
        $this->setHelp(
            "Generate a memory analysis report from a snapshot.\n\n"
            . "Tuning knobs (--ffi-csr / --link-cache / --substrate-bulk-fetch-chunk\n"
            . "/ --report-workers / --mmap-size / --prefetch / --no-derived-cache\n"
            . "/ --rebuild-derived-cache) only matter on large snapshots; their\n"
            . "background and which-knob-when guidance live in\n"
            . "docs/internals/memory-report-tuning.md.\n",
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

        // Detect rmem (.rmem) vs SQLite by extension or magic bytes
        $is_rmem = $this->isRmemFile($db_file);

        $generator = new ReportGenerator();

        if ($is_rmem) {
            // rmem path: no SQLite, no PDO, no mmap_size/prefetch/etc.
            /** @var bool|null $ffi_csr */
            $ffi_csr = $input->getOption('ffi-csr');

            // Prefetch the rmem file into kernel page cache too
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
     * Detect rmem format by extension (.rmem) or magic bytes ("RMEM").
     */
    private function isRmemFile(string $path): bool
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
