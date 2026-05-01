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

namespace Reli\Inspector\Daemon\PhpSpy;

use Reli\Converter\ParsedCallTrace;
use Reli\Converter\StreamingPhpSpyParser;
use Reli\Inspector\Settings\PhpSpySettings\PhpSpySettings;
use Reli\Lib\PhpSpy\PhpSpyFinder;
use Reli\Lib\PhpSpy\PhpSpyProcess;

final class PhpSpyProcessPool
{
    /** @var array<int, PhpSpyProcess> pid => process */
    private array $processes = [];

    /** @var array<int, StreamingPhpSpyParser> pid => parser */
    private array $parsers = [];

    public function __construct(
        private PhpSpyFinder $phpspy_finder,
    ) {
    }

    public function attach(
        int $pid,
        int $eg_address,
        int $sg_address,
        int $depth,
        PhpSpySettings $settings,
    ): void {
        if (isset($this->processes[$pid])) {
            return;
        }
        $process = new PhpSpyProcess($this->phpspy_finder);
        $process->start($pid, $eg_address, $sg_address, $depth, $settings);
        $this->processes[$pid] = $process;
    }

    public function detach(int $pid): void
    {
        if (isset($this->processes[$pid])) {
            $this->processes[$pid]->stop();
            unset($this->processes[$pid]);
        }
        unset($this->parsers[$pid]);
    }

    /**
     * Passthrough output from all running phpspy processes.
     *
     * @param resource $output_stream
     */
    public function passthroughAll($output_stream): void
    {
        foreach ($this->processes as $pid => $process) {
            if (!$process->isRunning()) {
                $process->passthrough($output_stream);
                $process->stop();
                unset($this->processes[$pid]);
                continue;
            }
            $process->passthrough($output_stream);
        }
    }

    /**
     * Drain pending bytes from each running phpspy, feed them into a
     * per-pid streaming parser and invoke `$sink($pid, $trace)` for
     * every completed trace. Each phpspy child has its own pipe and
     * its own parser, so byte-level interleaving across sources is
     * impossible and traces are always emitted whole.
     *
     * @param callable(int, ParsedCallTrace): void $sink
     */
    public function consumeAll(callable $sink): void
    {
        foreach ($this->processes as $pid => $process) {
            $still_running = $process->isRunning();
            $chunk = $process->readAvailable();

            if ($chunk !== '') {
                $parser = $this->parsers[$pid] ??= new StreamingPhpSpyParser();
                foreach ($parser->feed($chunk) as $trace) {
                    $sink($pid, $trace);
                }
            }

            if (!$still_running) {
                if (isset($this->parsers[$pid])) {
                    foreach ($this->parsers[$pid]->flush() as $trace) {
                        $sink($pid, $trace);
                    }
                    unset($this->parsers[$pid]);
                }
                $process->stop();
                unset($this->processes[$pid]);
            }
        }
    }

    /** @return list<int> */
    public function getActivePids(): array
    {
        return array_keys($this->processes);
    }

    public function hasProcess(int $pid): bool
    {
        return isset($this->processes[$pid]);
    }

    public function stopAll(): void
    {
        foreach ($this->processes as $process) {
            $process->stop();
        }
        $this->processes = [];
        $this->parsers = [];
    }

    /**
     * Drain any remaining bytes that the streaming parsers have already
     * buffered but not yet emitted (e.g. a final trace with no trailing
     * blank line). Call this after stopAll() during shutdown.
     *
     * @param callable(int, ParsedCallTrace): void $sink
     */
    public function flushParsers(callable $sink): void
    {
        foreach ($this->parsers as $pid => $parser) {
            foreach ($parser->flush() as $trace) {
                $sink($pid, $trace);
            }
        }
        $this->parsers = [];
    }

    public function count(): int
    {
        return count($this->processes);
    }
}
