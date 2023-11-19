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

namespace Reli\Inspector\Daemon;

use Reli\Lib\Amphp\ContextInterface;
use Reli\Lib\Amphp\MessageProtocolInterface;

/** @template T of MessageProtocolInterface */
interface AutoContextRecoveringInterface
{
    public function recreateContext(): void;

    /** @return ContextInterface<T> */
    public function getContext(): ContextInterface;

    /**
     * @template TReturn
     * @param \Closure(T):TReturn $callback
     * @return TReturn
     */
    public function withAutoRecover(
        \Closure $callback,
        string $log_message_on_retry,
    ): mixed;

    /** @param \Closure():void $callback */
    public function onRecover(\Closure $callback): void;
}
