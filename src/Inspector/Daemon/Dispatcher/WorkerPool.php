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

namespace Reli\Inspector\Daemon\Dispatcher;

use Reli\Inspector\Daemon\Reader\Context\PhpReaderContextCreatorInterface;
use Reli\Inspector\Daemon\Reader\Controller\PhpReaderControllerInterface;
use Reli\Inspector\Settings\GetTraceSettings\GetTraceSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;

use function array_fill;
use function array_keys;
use function count;

final class WorkerPool implements WorkerPoolInterface
{
    /** @var array<int, PhpReaderControllerInterface> */
    private array $contexts;

    /** @var array<int, bool> */
    private array $is_free_list;

    /** @no-named-arguments */
    public function __construct(PhpReaderControllerInterface ...$contexts)
    {
        $this->contexts = $contexts;
        $this->is_free_list = array_fill(0, count($contexts), true);
    }

    public static function create(
        PhpReaderContextCreatorInterface $creator,
        int $number,
        TraceLoopSettings $loop_settings,
        GetTraceSettings $get_trace_settings
    ): self {
        /** @var list<PhpReaderControllerInterface> $contexts */
        $contexts = [];
        for ($i = 0; $i < $number; $i++) {
            $context = $creator->create();
            $context->start();
            $contexts[] = $context;
        }
        $send_settings = [];
        for ($i = 0; $i < $number; $i++) {
            $contexts[$i]->sendSettings(
                $loop_settings,
                $get_trace_settings
            );
        }

        return new self(...$contexts);
    }

    public function getFreeWorker(): ?PhpReaderControllerInterface
    {
        foreach ($this->contexts as $key => $context) {
            if ($this->is_free_list[$key]) {
                $this->is_free_list[$key] = false;
                return $context;
            }
        }
        return null;
    }

    /** @return iterable<int, PhpReaderControllerInterface> */
    public function getWorkers(): iterable
    {
        foreach ($this->contexts as $key => $context) {
            yield $key => $context;
        }
    }

    public function returnWorkerToPool(PhpReaderControllerInterface $context_to_return): void
    {
        foreach ($this->contexts as $key => $context) {
            if ($context === $context_to_return) {
                $this->is_free_list[$key] = true;
            }
        }
    }

    public function debugDump(): array
    {
        return [
            'all' => array_keys($this->contexts),
            'is_free_list' => $this->is_free_list,
        ];
    }
}
