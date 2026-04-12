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

namespace Reli\Inspector\Output\MemoryOutput\Report;

/**
 * Fan-out runner for independent report passes.
 *
 * Phase 3 passes are all read-only over the substrate + the SQLite
 * analysis DB: each one walks a slice of the graph and returns a
 * list of Findings, without mutating anything the other passes
 * depend on. That makes them trivially parallel on a multi-core
 * host — the only reason they used to run sequentially was that
 * the sequential version was simple.
 *
 * The runner uses `pcntl_fork` on platforms that support it. The
 * parent builds the substrate once (an FFI-backed ~5 GB structure
 * on big captures) and each forked child inherits it via the
 * kernel's copy-on-write pages. Phase 3 passes only read from the
 * substrate, so no actual page copies happen — each worker gets
 * shared-memory access to the parent's substrate "for free".
 *
 * Workers serialise their Finding[] to a temp file as JSON
 * (via Finding::toArray) and exit. The parent reaps the workers
 * via waitpid, reads back the JSON files, and reconstitutes
 * Finding objects via Finding::fromArray. Temp files live under
 * a per-run directory that's cleaned up in a finally block.
 *
 * When worker_count <= 1, falls through to plain sequential
 * execution in the parent process (no fork, same PDO). This is
 * the default and matches the legacy behaviour exactly.
 */
final class ParallelPassRunner
{
    public function __construct(
        private int $worker_count,
    ) {
    }

    /**
     * @param array<string, callable(): list<Finding>> $pass_factories
     *   Factories keyed by a short pass name. Each factory returns
     *   the Findings from exactly one pass. The factory body runs
     *   either in the parent (sequential mode) or in a forked
     *   child (parallel mode) — so anything it captures must work
     *   after fork. In particular, PDO connections must be re-opened
     *   inside the closure; the substrate is fine to capture since
     *   it's read-only and CoW-shared.
     *
     * @return list<Finding>
     */
    public function run(array $pass_factories): array
    {
        if ($this->worker_count <= 1 || count($pass_factories) <= 1) {
            return $this->runSequential($pass_factories);
        }
        if (!function_exists('pcntl_fork')) {
            // Pcntl not available; fall back to sequential.
            return $this->runSequential($pass_factories);
        }
        return $this->runParallel($pass_factories);
    }

    /**
     * @param array<string, callable(): list<Finding>> $pass_factories
     * @return list<Finding>
     */
    private function runSequential(array $pass_factories): array
    {
        $findings = [];
        foreach ($pass_factories as $factory) {
            foreach ($factory() as $f) {
                $findings[] = $f;
            }
        }
        return $findings;
    }

    /**
     * @param array<string, callable(): list<Finding>> $pass_factories
     * @return list<Finding>
     */
    private function runParallel(array $pass_factories): array
    {
        $tmp_dir = $this->makeTempDir();
        try {
            /** @var array<int, array{name:string, out_file:string}> $running */
            $running = [];
            /** @var array<int, array{name:string, out_file:string}> $completed */
            $completed = [];
            $pending = $pass_factories;

            while ($pending !== [] || $running !== []) {
                // Fork new workers up to worker_count at a time.
                while (count($running) < $this->worker_count && $pending !== []) {
                    // $pending !== [] guarantees array_key_first is non-null,
                    // and $pass_factories is keyed by string per the type
                    // hint, so $name is always a string here.
                    $name = array_key_first($pending);
                    $factory = $pending[$name];
                    unset($pending[$name]);

                    $safe_name = preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? $name;
                    $out_file = $tmp_dir . '/' . $safe_name . '.json';

                    $pid = pcntl_fork();
                    if ($pid === -1) {
                        throw new \RuntimeException('pcntl_fork() failed');
                    }
                    if ($pid === 0) {
                        // Child process.
                        $this->childRun($factory, $out_file);
                        // childRun calls exit() itself — never returns.
                    }
                    $running[$pid] = ['name' => $name, 'out_file' => $out_file];
                }

                // Reap one finished child.
                if ($running === []) {
                    break;
                }
                $status = 0;
                $pid = pcntl_wait($status);
                if ($pid <= 0) {
                    break;
                }
                if (!isset($running[$pid])) {
                    continue;
                }
                $completed[$pid] = $running[$pid];
                unset($running[$pid]);
                if (pcntl_wifexited($status) && pcntl_wexitstatus($status) !== 0) {
                    fwrite(
                        STDERR,
                        sprintf(
                            "parallel pass worker '%s' exited with status %d\n",
                            $completed[$pid]['name'],
                            pcntl_wexitstatus($status),
                        ),
                    );
                }
            }

            // Collect findings from every successful worker.
            $all = [];
            foreach ($completed as $entry) {
                $out_file = $entry['out_file'];
                if (!file_exists($out_file)) {
                    continue;
                }
                $json = file_get_contents($out_file);
                if ($json === false) {
                    continue;
                }
                $arr = json_decode($json, true);
                if (!is_array($arr)) {
                    continue;
                }
                /** @psalm-suppress MixedAssignment json_decode result is genuinely mixed */
                foreach ($arr as $item) {
                    if (is_array($item)) {
                        $all[] = Finding::fromArray($item);
                    }
                }
            }
            return $all;
        } finally {
            $this->cleanupTempDir($tmp_dir);
        }
    }

    /**
     * @param callable(): list<Finding> $factory
     */
    private function childRun(callable $factory, string $out_file): never
    {
        try {
            $findings = $factory();
            $serialized = [];
            foreach ($findings as $f) {
                $serialized[] = $f->toArray();
            }
            $json = json_encode($serialized);
            if ($json === false) {
                fwrite(STDERR, "parallel pass worker: json_encode failed\n");
                exit(2);
            }
            file_put_contents($out_file, $json);
            exit(0);
        } catch (\Throwable $e) {
            fwrite(
                STDERR,
                sprintf(
                    "parallel pass worker failed: %s\n%s\n",
                    $e->getMessage(),
                    $e->getTraceAsString(),
                ),
            );
            exit(1);
        }
    }

    private function makeTempDir(): string
    {
        $base = sys_get_temp_dir();
        $tmp = $base . '/reli_parallel_' . posix_getpid() . '_' . uniqid('', true);
        if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            throw new \RuntimeException("failed to create temp dir: {$tmp}");
        }
        return $tmp;
    }

    private function cleanupTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*');
        if ($files === false) {
            $files = [];
        }
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
