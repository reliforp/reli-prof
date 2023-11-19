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

namespace Reli\Lib\Loop\LoopMiddleware;

use Reli\Lib\Log\Log;
use Reli\Lib\Loop\LoopMiddlewareInterface;

class ExitLoopOnSpecificExceptionMiddleware implements LoopMiddlewareInterface
{
    /** @param list<class-string<\Throwable>> $exception_names */
    public function __construct(
        private array $exception_names,
        private LoopMiddlewareInterface $chain,
    ) {
    }
    public function invoke(): bool
    {
        try {
            return $this->chain->invoke();
        } catch (\Throwable $e) {
            foreach ($this->exception_names as $exception_name) {
                /** @psalm-suppress DocblockTypeContradiction */
                if (is_a($e, $exception_name)) {
                    Log::debug(
                        $e->getMessage(),
                        [
                            'trace' => $e->getTrace()
                        ]
                    );
                    return false;
                }
            }
            throw $e;
        }
    }
}
