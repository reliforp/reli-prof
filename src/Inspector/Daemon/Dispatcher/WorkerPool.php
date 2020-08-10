<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Daemon\Dispatcher;

use Amp\Promise;
use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContextCreatorInterface;
use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContextInterface;
use PhpProfiler\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;

final class WorkerPool implements WorkerPoolInterface
{
    /** @var array<int, PhpReaderContextInterface> */
    private array $contexts;

    /** @var array<int, bool> */
    private array $is_free_list;

    /** @var array<int, bool> */
    private array $on_read_list;

    public function __construct(PhpReaderContextInterface ...$contexts)
    {
        $this->contexts = $contexts;
        $this->is_free_list = array_fill(0, count($contexts), true);
        $this->on_read_list = array_fill(0, count($contexts), false);
    }

    public static function create(
        PhpReaderContextCreatorInterface $creator,
        int $number,
        TargetPhpSettings $target_php_settings,
        TraceLoopSettings $loop_settings,
        GetTraceSettings $get_trace_settings
    ): self {
        $contexts = [];
        $started = [];
        for ($i = 0; $i < $number; $i++) {
            $context = $creator->create();
            $started[] = $context->start();
            $contexts[] = $context;
        }
        Promise\wait(Promise\all($started));
        $send_settings = [];
        for ($i = 0; $i < $number; $i++) {
            $send_settings[] = $contexts[$i]->sendSettings(
                $target_php_settings,
                $loop_settings,
                $get_trace_settings
            );
        }
        Promise\wait(Promise\all($send_settings));

        return new self(...$contexts);
    }

    public function getFreeWorker(): ?PhpReaderContextInterface
    {
        foreach ($this->contexts as $key => $context) {
            if ($this->is_free_list[$key]) {
                $this->is_free_list[$key] = false;
                return $context;
            }
        }
        return null;
    }

    /**
     * @return iterable<int, PhpReaderContextInterface>
     */
    public function getReadableWorkers(): iterable
    {
        foreach ($this->contexts as $key => $context) {
            if (!$this->is_free_list[$key] and !$this->on_read_list[$key]) {
                yield $key => $context;
            }
        }
    }

    public function returnWorkerToPool(PhpReaderContextInterface $context_to_return): void
    {
        foreach ($this->contexts as $key => $context) {
            if ($context === $context_to_return) {
                $this->is_free_list[$key] = true;
                $this->on_read_list[$key] = false;
            }
        }
    }

    public function setOnRead(int $pid): void
    {
        $this->on_read_list[$pid] = true;
    }

    public function releaseOnRead(int $pid): void
    {
        $this->on_read_list[$pid] = false;
    }
}
