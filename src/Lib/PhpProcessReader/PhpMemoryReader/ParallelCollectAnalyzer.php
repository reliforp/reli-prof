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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader;

use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryLimitErrorDetails;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PipeContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\SingleLinkContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;

/**
 * Parallelizes the entire collect + analyze pipeline by forking
 * workers that each handle a group of branches.
 *
 * Architecture:
 *   Main process: prepare() → fork workers → read pipes → PdoContextTreeSink → DB
 *   Worker N:     collect assigned branches → ContextAnalyzer → PipeContextTreeSink → pipe
 *
 * Workers use local memory_locations / context_pools (no shared state).
 * Cross-branch deduplication is lost; duplicate traversal of shared
 * nodes is accepted as a trade-off for parallelism.
 */
final class ParallelCollectAnalyzer
{
    private const NODE_ID_RANGE_PER_WORKER = 100_000_000;

    public static function isAvailable(): bool
    {
        return function_exists('pcntl_fork')
            && function_exists('pcntl_waitpid')
            && function_exists('pcntl_wifexited')
            && function_exists('pcntl_wexitstatus');
    }

    /**
     * Branch definitions: each entry is [name, callable(collector, prep, memory_locations, context_pools) => ReferenceContext].
     *
     * @return list<array{string, \Closure}>
     */
    private static function branchDefinitions(
        CollectionPreparation $prep,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): array {
        $d = $prep->dereferencer;
        $z = $prep->zend_type_reader;
        $eg = $prep->eg;
        $cg = $prep->cg;
        $map_ptr_base = $cg->map_ptr_base;

        return [
            ['included_files', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($eg, $d) {
                return $c->collectIncludedFiles($eg->included_files, $d, $ml, $cp);
            }],
            ['interned_strings', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($cg, $map_ptr_base, $d, $z) {
                return $c->collectInternedStrings($cg->interned_strings, $map_ptr_base, $d, $z, $ml, $cp);
            }],
            ['global_variables', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($eg, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectGlobalVariables($eg->symbol_table, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }],
            ['call_frames', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($eg, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectCallFrames($eg, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }],
            ['function_table', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($prep, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectFunctionTable($prep->function_table, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }],
            ['class_table', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($prep, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectClassTable($prep->class_table, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }],
            ['global_constants', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($prep, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectGlobalConstants($prep->zend_constants, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }],
            ['objects_store', function (MemoryLocationsCollector $c, MemoryLocations $ml, ContextPools $cp) use ($eg, $map_ptr_base, $d, $z, $memory_limit_error_details) {
                return $c->collectObjectsStore($eg->objects_store, $map_ptr_base, $d, $z, $ml, $cp, $memory_limit_error_details);
            }],
        ];
    }

    /**
     * Run the parallel collect + analyze pipeline, writing results to the given sink.
     *
     * @throws \RuntimeException if any worker fails
     */
    public function run(
        MemoryLocationsCollector $collector,
        CollectionPreparation $prep,
        PdoContextTreeSink $sink,
        ?RegionBoundaries $region_boundaries,
        ?MemoryLimitErrorDetails $memory_limit_error_details = null,
    ): void {
        $branches = self::branchDefinitions($prep, $memory_limit_error_details);

        // Create pipes.
        /** @var array<int, resource[]> $pipes */
        $pipes = [];
        foreach ($branches as $index => $_) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false) {
                throw new \RuntimeException('stream_socket_pair() failed');
            }
            $pipes[$index] = $pair;
        }

        // Fork workers.
        /** @var array<int, int> $child_pids */
        $child_pids = [];

        foreach ($branches as $index => [$branch_name, $collect_fn]) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->waitForChildren($child_pids);
                throw new \RuntimeException('pcntl_fork() failed');
            }
            if ($pid === 0) {
                // ---- Worker process ----
                // Close all read ends and other workers' write ends.
                foreach ($pipes as $i => $pair) {
                    fclose($pair[0]);
                    if ($i !== $index) {
                        fclose($pair[1]);
                    }
                }
                $write_fd = $pipes[$index][1];

                try {
                    // Each worker has its own memory_locations and context_pools.
                    $ml = new MemoryLocations();
                    foreach ($prep->huge_list_memory_locations->memory_locations as $loc) {
                        $ml->add($loc);
                    }
                    $cp = ContextPools::createDefault();

                    /** @var ReferenceContext $branch_context */
                    $branch_context = $collect_fn($collector, $ml, $cp);

                    // Analyze and pipe results.
                    $pipe_sink = new PipeContextTreeSink($write_fd, $region_boundaries);
                    $analyzer = new ContextAnalyzer($index * self::NODE_ID_RANGE_PER_WORKER);
                    $wrapper = new SingleLinkContext($branch_name, $branch_context);
                    $analyzer->analyze($wrapper, $pipe_sink);
                    $pipe_sink->sendEnd();
                    fclose($write_fd);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "ParallelCollectAnalyzer worker {$index} ({$branch_name}) error: {$e->getMessage()}\n");
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

        // ---- Main process: close write ends, drain pipes ----
        foreach ($pipes as $pair) {
            fclose($pair[1]);
        }

        $this->drainPipes($pipes, $sink);
        $sink->flush();

        foreach ($pipes as $pair) {
            fclose($pair[0]);
        }

        $this->waitForChildren($child_pids);
    }

    /**
     * @param array<int, resource[]> $pipes
     */
    private function drainPipes(array &$pipes, PdoContextTreeSink $sink): void
    {
        /** @var array<int, resource> $active */
        $active = [];
        $buffers = [];
        foreach ($pipes as $index => $pair) {
            $active[$index] = $pair[0];
            stream_set_blocking($pair[0], false);
            $buffers[$index] = '';
        }

        while ($active !== []) {
            $r = $active;
            $w = null;
            $e = null;
            $changed = @stream_select($r, $w, $e, 1);
            if ($changed === false) {
                break;
            }
            if ($changed === 0) {
                continue;
            }

            foreach ($r as $fd) {
                $index = array_search($fd, $active, true);
                if ($index === false) {
                    continue;
                }

                $chunk = fread($fd, 65536);
                if ($chunk === false || $chunk === '') {
                    unset($active[$index]);
                    continue;
                }

                $buffers[$index] .= $chunk;

                while (strlen($buffers[$index]) >= 4) {
                    /** @var array{len: int} $unpacked */
                    $unpacked = unpack('Vlen', $buffers[$index]);
                    $msg_len = $unpacked['len'];

                    if (strlen($buffers[$index]) < 4 + $msg_len) {
                        break;
                    }

                    $payload = substr($buffers[$index], 4, $msg_len);
                    $buffers[$index] = substr($buffers[$index], 4 + $msg_len);

                    /** @var list<mixed> $message */
                    $message = unserialize($payload);

                    if ($message[0] === 'E') {
                        unset($active[$index]);
                        break;
                    }

                    $this->replayMessage($message, $sink);
                }
            }
        }
    }

    /**
     * @param list<mixed> $message
     */
    private function replayMessage(array $message, PdoContextTreeSink $sink): void
    {
        switch ($message[0]) {
            case 'N':
                /** @var array{1: int, 2: ?int, 3: string, 4: string, 5: list<array{int, int, string, ?string, ?string, ?int, ?int, ?string}>, 6: array<string, mixed>} $message */
                $sink->emitNodeFlat($message[1], $message[2], $message[3], $message[4], $message[5], $message[6]);
                break;
            case 'R':
                /** @var array{1: int, 2: ?int, 3: string} $message */
                $sink->emitReference($message[1], $message[2], $message[3]);
                break;
        }
    }

    /**
     * @param array<int, int> $child_pids
     * @throws \RuntimeException if any child exited with non-zero status
     */
    private function waitForChildren(array $child_pids): void
    {
        $errors = [];
        foreach ($child_pids as $index => $pid) {
            pcntl_waitpid($pid, $status);
            if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                $errors[] = "worker {$index} (pid {$pid}) exited with status {$status}";
            }
        }
        if ($errors !== []) {
            throw new \RuntimeException(
                'ParallelCollectAnalyzer: worker failures: ' . implode('; ', $errors)
            );
        }
    }
}
