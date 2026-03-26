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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $db_path = $input->getArgument('db-path');
        assert(is_string($db_path));

        if (!file_exists($db_path)) {
            $output->writeln("<error>File not found: {$db_path}</error>");
            return 1;
        }

        $db = new \PDO('sqlite:' . $db_path);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Check if already materialized
        $exists = $db->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='node_paths'"
        )->fetchColumn();
        if ($exists) {
            $output->writeln('node_paths table already exists, dropping and recreating...');
            $db->exec('DROP TABLE node_paths');
        }

        $node_count = $db->query('SELECT COUNT(*) FROM context_nodes')->fetchColumn();
        $output->writeln("Materializing node_paths for {$node_count} nodes...");
        $output->writeln('<comment>Note: this may significantly increase the database file size</comment>');

        $start = hrtime(true);

        $db->exec('
            CREATE TABLE node_paths (
                node_id INTEGER NOT NULL PRIMARY KEY,
                path TEXT NOT NULL,
                depth INTEGER NOT NULL
            )
        ');
        $db->exec("
            INSERT INTO node_paths (node_id, path, depth)
            WITH RECURSIVE cte(node_id, path, depth) AS (
                SELECT child_node_id, link_name, 0
                FROM context_edges
                WHERE parent_node_id IS NULL AND is_tree = 1
              UNION ALL
                SELECT e.child_node_id, cte.path || ' -> ' || e.link_name, cte.depth + 1
                FROM context_edges e
                JOIN cte ON e.parent_node_id = cte.node_id
                WHERE e.is_tree = 1
            )
            SELECT node_id, path, depth FROM cte
        ");
        $db->exec('CREATE INDEX idx_node_paths_depth ON node_paths(depth)');

        $output->writeln('Compacting database...');
        $db->exec('VACUUM');

        $elapsed = (hrtime(true) - $start) / 1e9;
        $row_count = $db->query('SELECT COUNT(*) FROM node_paths')->fetchColumn();

        $output->writeln(sprintf(
            'Done: %s rows materialized in %.1fs',
            number_format((int)$row_count),
            $elapsed,
        ));

        return 0;
    }
}
