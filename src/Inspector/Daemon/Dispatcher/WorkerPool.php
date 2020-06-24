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

use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContext;
use PhpProfiler\Inspector\Daemon\Reader\Context\PhpReaderContextCreator;
use Amp\Promise;

final class WorkerPool
{
    /** @var array<int, PhpReaderContext> */
    private array $contexts;

    /** @var array<int, bool> */
    private array $is_free_list;

    public function __construct(PhpReaderContext ...$contexts)
    {
        $this->contexts = $contexts;
        $this->is_free_list = array_fill(0, count($contexts), true);
    }

    public static function create(PhpReaderContextCreator $creator, int $number): self
    {
        $contexts = [];
        $started = [];
        for ($i = 0; $i < $number; $i++) {
            $context = $creator->create();
            $started[] = $context->start();
            $contexts[] = $context;
        }
        Promise\wait(Promise\all($started));
        return new self(...$contexts);
    }

    public function getFreeWorker(): ?PhpReaderContext
    {
        foreach ($this->contexts as $key => $context) {
            if ($this->is_free_list[$key]) {
                $this->is_free_list[$key] = false;
                return $context;
            }
        }
        return null;
    }

    public function returnWorkerToPool(PhpReaderContext $context_to_return): void
    {
        foreach ($this->contexts as $key => $context) {
            if ($context === $context_to_return) {
                $this->is_free_list[$key] = true;
            }
        }
    }
}
