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

namespace Reli\Inspector\Watch\Action;

use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\TriggerEvent;
use Reli\Inspector\Watch\WatchContext;
use Reli\Lib\Process\ProcessSpecifier;

final class LogAction implements ActionInterface
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream  Log output stream. Defaults to STDERR.
     */
    public function __construct(
        $stream = null,
    ) {
        $this->stream = $stream ?? \STDERR;
    }

    /**
     * @param string $path  Path to log file
     */
    public static function toFile(string $path): self
    {
        $stream = fopen($path, 'a');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open log file: {$path}");
        }
        return new self($stream);
    }

    #[\Override]
    public function name(): string
    {
        return 'log';
    }

    #[\Override]
    public function execute(
        TriggerEvent $event,
        ProcessSpecifier $process,
        WatchContext $context,
    ): void {
        $line = sprintf(
            "[%s] PID=%d trigger=%s %s mem=%s peak=%s\n",
            date('c', (int)$event->timestamp),
            $process->pid,
            $event->trigger_name,
            $event->description,
            HeapStats::humanReadableBytes($context->heap_stats->size),
            HeapStats::humanReadableBytes($context->heap_stats->peak),
        );
        fwrite($this->stream, $line);
    }
}
