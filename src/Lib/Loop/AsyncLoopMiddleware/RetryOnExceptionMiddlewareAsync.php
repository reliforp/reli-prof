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

namespace PhpProfiler\Lib\Loop\AsyncLoopMiddleware;

use PhpProfiler\Lib\Log\Log;
use PhpProfiler\Lib\Loop\AsyncLoopMiddlewareInterface;
use Throwable;

final class RetryOnExceptionMiddlewareAsync implements AsyncLoopMiddlewareInterface
{
    private int $current_retry_count = 0;

    /**
     * @param array<int, class-string<Throwable>> $exception_names
     */
    public function __construct(
        private int $max_retry,
        private array $exception_names,
        private AsyncLoopMiddlewareInterface $chain
    ) {
    }

    public function invoke(): \Generator
    {
        while ($this->current_retry_count <= $this->max_retry or $this->max_retry === -1) {
            try {
                yield from $this->chain->invoke();
            } catch (Throwable $e) {
                Log::debug($e->getMessage(), [
                    'exception' => $e,
                    'trace' => $e->getTrace()
                ]);
                foreach ($this->exception_names as $exception_name) {
                    /** @psalm-suppress DocblockTypeContradiction */
                    if (is_a($e, $exception_name)) {
                        $this->current_retry_count++;
                        Log::debug(
                            $e->getMessage(),
                            [
                                'retry_count' => $this->current_retry_count,
                                'trace' => $e->getTrace()
                            ]
                        );
                        continue 2;
                    }
                }
                throw $e;
            }
            $this->current_retry_count = 0;
        }
        assert(isset($e) and $e instanceof Throwable);
        Log::error(
            $e->getMessage(),
            [
                'retry_count' => $this->current_retry_count,
                'trace' => $e->getTrace()
            ]
        );
        throw $e;
    }
}
