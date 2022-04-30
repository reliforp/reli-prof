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

namespace PhpProfiler\Lib\Loop\LoopMiddleware;

use PhpProfiler\Lib\Log\Log;
use PhpProfiler\Lib\Loop\LoopMiddlewareInterface;
use Throwable;

final class RetryOnExceptionMiddleware implements LoopMiddlewareInterface
{
    private int $current_retry_count = 0;

    /**
     * @param array<int, class-string<Throwable>> $exception_names
     */
    public function __construct(
        private int $max_retry,
        private array $exception_names,
        private LoopMiddlewareInterface $chain,
    ) {
    }

    public function invoke(): bool
    {
        while ($this->current_retry_count <= $this->max_retry or $this->max_retry === -1) {
            try {
                $result = $this->chain->invoke();
                $this->current_retry_count = 0;
                return $result;
            } catch (Throwable $e) {
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
