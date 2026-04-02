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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MemoryDbOptimizeCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:optimize-memory-db')
            ->setDescription('[experimental] materialize node_paths in a memory analysis SQLite database')
            ->addArgument(
                'db-path',
                InputArgument::REQUIRED,
                'path to the SQLite database file',
            )
            ->addOption(
                'run-id',
                null,
                InputOption::VALUE_REQUIRED,
                'run ID to materialize (default: all runs)',
            )
            ->addOption(
                'memory-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'set PHP memory_limit for analysis (e.g. 2G, 512M)',
            )
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $memory_limit */
        $memory_limit = $input->getOption('memory-limit');
        if (is_string($memory_limit) && $memory_limit !== '') {
            ini_set('memory_limit', $memory_limit);
        }

        $db_path = $input->getArgument('db-path');
        assert(is_string($db_path));

        if (!file_exists($db_path)) {
            $output->writeln("<error>File not found: {$db_path}</error>");
            return 1;
        }

        $run_id = NullableCast::toInt($input->getOption('run-id'));

        $db = new \PDO('sqlite:' . $db_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Check if already materialized
        $exists = (int)$db->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='node_paths'"
        )->fetchColumn();
        if ($exists) {
            $output->writeln('node_paths table already exists, dropping and recreating...');
            $db->exec('DROP TABLE node_paths');
        }

        $where = $run_id !== null ? ' AND run_id = ' . $run_id : '';
        $node_count = (int)$db->query(
            'SELECT COUNT(*) FROM context_nodes WHERE 1=1' . $where
        )->fetchColumn();
        $label = $run_id !== null ? " (run_id={$run_id})" : ' (all runs)';
        $output->writeln("Materializing node_paths for {$node_count} nodes{$label}...");
        $output->writeln('<comment>Note: this may significantly increase the database file size</comment>');

        $start = hrtime(true);

        $db->exec('
            CREATE TABLE node_paths (
                run_id INTEGER NOT NULL,
                node_id INTEGER NOT NULL,
                path TEXT NOT NULL,
                depth INTEGER NOT NULL,
                PRIMARY KEY (run_id, node_id)
            )
        ');

        $run_filter = $run_id !== null ? ' AND run_id = ' . $run_id : '';
        $db->exec("
            INSERT INTO node_paths (run_id, node_id, path, depth)
            WITH RECURSIVE cte(run_id, node_id, path, depth) AS (
                SELECT run_id, child_node_id, link_name, 0
                FROM context_edges
                WHERE parent_node_id IS NULL AND is_tree = 1{$run_filter}
              UNION ALL
                SELECT cte.run_id, e.child_node_id, cte.path || ' -> ' || e.link_name, cte.depth + 1
                FROM context_edges e
                JOIN cte ON e.parent_node_id = cte.node_id AND e.run_id = cte.run_id
                WHERE e.is_tree = 1
            )
            SELECT run_id, node_id, path, depth FROM cte
        ");
        $db->exec('CREATE INDEX idx_node_paths_run_depth ON node_paths(run_id, depth)');

        $output->writeln('Compacting database...');
        $db->exec('VACUUM');

        $elapsed = (float)(hrtime(true) - $start) / 1e9;
        $row_count = (int)$db->query('SELECT COUNT(*) FROM node_paths')->fetchColumn();

        $output->writeln(sprintf(
            'Done: %s rows materialized in %.1fs',
            number_format($row_count),
            $elapsed,
        ));

        return 0;
    }
}
