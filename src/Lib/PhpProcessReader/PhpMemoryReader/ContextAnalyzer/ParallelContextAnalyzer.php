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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer;

use Reli\Inspector\Output\MemoryOutput\PdoDriver\PdoDriverInterface;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

/**
 * Parallelizes the context-graph traversal by forking one child process
 * per top-level branch of the ReferenceContext graph.
 *
 * Each child opens its own DB connection and writes its subtree
 * independently.  Node-ID ranges are partitioned so that children never
 * collide (each branch gets a 100 000 000-wide ID band).
 *
 * For nodes that are reachable from multiple branches (DAG cross-edges),
 * each child traverses them independently.  The INSERT-OR-IGNORE
 * semantics of context_nodes prevent exact duplicates when two children
 * happen to assign the same ID (which cannot happen with the current
 * partitioning), and the minor data duplication across different ID
 * ranges is an acceptable trade-off for the parallelism gained.
 */
final class ParallelContextAnalyzer
{
    private const NODE_ID_RANGE_PER_BRANCH = 100_000_000;

    public static function isAvailable(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('pcntl_wifexited')
            && function_exists('pcntl_wexitstatus');
    }

    /**
     * @throws \RuntimeException if any child process fails
     */
    public function analyze(
        ReferenceContext $root,
        PdoDriverInterface $driver,
        int $run_id,
        ?RegionBoundaries $region_boundaries = null,
    ): void {
        $branches = [];
        foreach ($root->getLinks() as $link_name => $linked_context) {
            /** @psalm-suppress RedundantCastGivenDocblockType -- int keys occur at runtime */
            $branches[] = [(string)$link_name, $linked_context];
        }

        if ($branches === []) {
            return;
        }

        // For a single branch, skip forking overhead.
        if (count($branches) === 1) {
            $this->processBranch(
                $branches[0][0],
                $branches[0][1],
                $driver,
                $run_id,
                0,
                $region_boundaries,
            );
            return;
        }

        $child_pids = [];
        foreach ($branches as $index => [$link_name, $linked_context]) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                // Fork failed — wait for already-forked children, then throw.
                $this->waitForChildren($child_pids);
                throw new \RuntimeException('pcntl_fork() failed');
            }
            if ($pid === 0) {
                // Child process
                try {
                    $this->processBranch(
                        $link_name,
                        $linked_context,
                        $driver,
                        $run_id,
                        $index * self::NODE_ID_RANGE_PER_BRANCH,
                        $region_boundaries,
                    );
                } catch (\Throwable $e) {
                    fwrite(STDERR, "ParallelContextAnalyzer child {$index} error: {$e->getMessage()}\n");
                    // @codeCoverageIgnoreStart
                    exit(1);
                    // @codeCoverageIgnoreEnd
                }
                // @codeCoverageIgnoreStart
                exit(0);
                // @codeCoverageIgnoreEnd
            }
            $child_pids[$index] = $pid;
        }

        $this->waitForChildren($child_pids);
    }

    private function processBranch(
        string $link_name,
        ReferenceContext $linked_context,
        PdoDriverInterface $driver,
        int $run_id,
        int $start_node_id,
        ?RegionBoundaries $region_boundaries,
    ): void {
        $db = $driver->createConnection();
        $driver->tuneForParallelInsert($db);

        $db->beginTransaction();

        $sink = new PdoContextTreeSink($db, $driver, $run_id, $region_boundaries);
        $analyzer = new ContextAnalyzer($start_node_id);
        $wrapper = new SingleLinkContext($link_name, $linked_context);

        $analyzer->analyze($wrapper, $sink);
        $sink->flush();

        $db->commit();
    }

    /**
     * @param array<int, int> $child_pids  index => pid
     * @throws \RuntimeException if any child exited with non-zero status
     */
    private function waitForChildren(array $child_pids): void
    {
        $errors = [];
        foreach ($child_pids as $index => $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $errors[] = "branch {$index} (pid {$pid}) exited with status {$status}";
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException(
                'ParallelContextAnalyzer: child process failures: ' . implode('; ', $errors)
            );
        }
    }
}
