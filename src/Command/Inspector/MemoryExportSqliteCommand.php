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

use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Inspector\Output\MemoryOutput\PdoMemoryOutput;
use Reli\Inspector\Output\MemoryOutput\Report\BinaryReportDataProvider;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Export an `.rmem` capture as a SQLite database.
 *
 * The `.rmem` format is the canonical intermediate for analyze /
 * report / json — it powers the in-memory substrate that the
 * binary-backed report and TUI consume directly without going
 * through SQL. SQLite remains valuable as a low-friction inspection
 * surface (DBeaver, sqlite3 CLI, third-party dashboards), so this
 * command writes one out on demand from an existing rmem.
 *
 * Equivalent to `inspector:memory:analyze … -F sqlite3` in output,
 * but skips the dump-parse + collect phase: when you already have
 * a saved rmem (e.g. from a previous analyze run) and just want
 * the SQL view of it, this is the fast path.
 *
 * Reuses the existing rmem -> SQLite ingester (parallel-shard
 * with format-direct table merge by default; opt into shared-mmap
 * + parallel CREATE INDEX with RELI_SHARED_MMAP_INGEST=1
 * RELI_PARALLEL_INDEX=1). Output is identical to the analyze
 * command's sqlite3 format.
 */
final class MemoryExportSqliteCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:export-sqlite')
            ->setDescription(
                'Export an .rmem capture as a SQLite database for ad-hoc SQL inspection'
            )
            ->addArgument(
                'input',
                InputArgument::REQUIRED,
                'path to the input .rmem file',
            )
            ->addArgument(
                'output',
                InputArgument::REQUIRED,
                'path for the output .sqlite3 file',
            )
            ->addMemoryLimitOption()
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);
        $rmem_path = (string)$input->getArgument('input');
        $db_path = (string)$input->getArgument('output');

        if (!file_exists($rmem_path)) {
            $output->writeln("<error>File not found: {$rmem_path}</error>");
            return 1;
        }
        if (file_exists($db_path)) {
            $output->writeln(
                "<error>Output already exists: {$db_path} (refusing to overwrite)</error>"
            );
            return 1;
        }

        $reader = BinaryReader::open($rmem_path);
        $summary = BinaryReportDataProvider::loadSummary($reader);
        unset($reader);

        $t_start = microtime(true);
        $pdo_output = new PdoMemoryOutput(new SqliteDriver($db_path));
        $pdo_output->ingestFromRmem($rmem_path, $summary);
        $t_end = microtime(true);

        $size_bytes = filesize($db_path);
        $output->writeln(sprintf(
            '<info>Wrote %s (%.1f MB) in %.2fs</info>',
            $db_path,
            ($size_bytes !== false ? (float)$size_bytes : 0.0) / 1048576.0,
            $t_end - $t_start,
        ));
        return 0;
    }
}
