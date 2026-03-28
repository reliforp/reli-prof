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
 * Parallelizes the context-graph traversal using a pipe-based
 * producer–consumer architecture.
 *
 * Workers (forked children) traverse their assigned branch of the
 * ReferenceContext graph and stream flattened records through a pipe.
 * The parent process reads from all pipes and feeds a single
 * PdoContextTreeSink — no temp files, no merge, no lock contention.
 *
 * Node-ID ranges are partitioned (100 000 000 IDs per branch) so that
 * children never collide.
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
     * Run the parallel analysis.
     *
     * The caller must pass a ready-to-use PdoContextTreeSink (already
     * inside a transaction).  This method writes to the sink and calls
     * flush() when done, but does NOT commit.
     *
     * @throws \RuntimeException if any child process fails
     */
    public function analyze(
        ReferenceContext $root,
        PdoContextTreeSink $sink,
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
            $analyzer = new ContextAnalyzer();
            $wrapper = new SingleLinkContext($branches[0][0], $branches[0][1]);
            $analyzer->analyze($wrapper, $sink);
            $sink->flush();
            return;
        }

        // Create pipes: one per worker.
        /** @var array<int, resource[]> $pipes  index => [read_fd, write_fd] */
        $pipes = [];
        /** @var array<int, int> $child_pids */
        $child_pids = [];

        foreach ($branches as $index => $_) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false) {
                throw new \RuntimeException('stream_socket_pair() failed');
            }
            $pipes[$index] = $pair;
        }

        // Fork workers.
        foreach ($branches as $index => [$link_name, $linked_context]) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->waitForChildren($child_pids);
                throw new \RuntimeException('pcntl_fork() failed');
            }
            if ($pid === 0) {
                // ---- Child process ----
                // Close all read ends and other workers' write ends.
                foreach ($pipes as $i => $pair) {
                    fclose($pair[0]); // close read end
                    if ($i !== $index) {
                        fclose($pair[1]); // close other workers' write ends
                    }
                }
                $write_fd = $pipes[$index][1];

                try {
                    $pipe_sink = new PipeContextTreeSink($write_fd, $region_boundaries);
                    $analyzer = new ContextAnalyzer($index * self::NODE_ID_RANGE_PER_BRANCH);
                    $wrapper = new SingleLinkContext($link_name, $linked_context);

                    $analyzer->analyze($wrapper, $pipe_sink);
                    $pipe_sink->sendEnd();
                    fclose($write_fd);
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

        // ---- Parent process ----
        // Close all write ends.
        foreach ($pipes as $pair) {
            fclose($pair[1]);
        }

        // Read from all pipes and feed the sink.
        /** @var array<int, resource> $read_fds  index => read fd */
        $read_fds = [];
        foreach ($pipes as $index => $pair) {
            $read_fds[$index] = $pair[0];
            stream_set_blocking($pair[0], false);
        }

        $this->drainPipes($read_fds, $sink);
        $sink->flush();

        // Clean up: close remaining read fds, wait for children.
        foreach ($read_fds as $fd) {
            if (is_resource($fd)) {
                fclose($fd);
            }
        }

        $this->waitForChildren($child_pids);
    }

    /**
     * Read messages from all worker pipes and replay them into the sink.
     *
     * Uses stream_select() to multiplex across all pipes, processing
     * whichever pipe has data ready.
     *
     * @param array<int, resource> $read_fds
     */
    private function drainPipes(array &$read_fds, PdoContextTreeSink $sink): void
    {
        $active = $read_fds;
        $buffers = array_fill_keys(array_keys($read_fds), '');
        $finished = [];

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
                    // Pipe closed — worker done.
                    $finished[$index] = true;
                    unset($active[$index]);
                    continue;
                }

                $buffers[$index] .= $chunk;

                // Process complete messages from this buffer.
                while (strlen($buffers[$index]) >= 4) {
                    $header = substr($buffers[$index], 0, 4);
                    /** @var array{len: int} $unpacked */
                    $unpacked = unpack('Vlen', $header);
                    $msg_len = $unpacked['len'];

                    if (strlen($buffers[$index]) < 4 + $msg_len) {
                        break; // Incomplete message, wait for more data.
                    }

                    $payload = substr($buffers[$index], 4, $msg_len);
                    $buffers[$index] = substr($buffers[$index], 4 + $msg_len);

                    /** @var array $message */
                    $message = unserialize($payload);
                    $this->replayMessage($message, $sink);

                    if ($message[0] === 'E') {
                        $finished[$index] = true;
                        unset($active[$index]);
                        break;
                    }
                }
            }
        }
    }

    /**
     * Replay a deserialized message into the PdoContextTreeSink.
     *
     * @param array $message
     */
    private function replayMessage(array $message, PdoContextTreeSink $sink): void
    {
        switch ($message[0]) {
            case 'N':
                // emitNode: [type, node_id, parent_node_id, link_name, type, flat_locations, attributes]
                [, $node_id, $parent_node_id, $link_name, $type, $flat_locations, $attributes] = $message;
                $sink->emitNodeFlat($node_id, $parent_node_id, $link_name, $type, $flat_locations, $attributes);
                break;
            case 'R':
                // emitReference: [type, reference_node_id, parent_node_id, link_name]
                [, $reference_node_id, $parent_node_id, $link_name] = $message;
                $sink->emitReference($reference_node_id, $parent_node_id, $link_name);
                break;
            case 'E':
                // End of stream — nothing to do.
                break;
        }
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
