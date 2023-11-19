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

use Amp\Parallel\Context\ContextException;
use Reli\Lib\Amphp\ContextInterface;
use Reli\Lib\Amphp\MessageProtocolInterface;
use Reli\Lib\Log\Log;

/**
 * @template T of MessageProtocolInterface
 * @implements AutoContextRecoveringInterface<T>
 */
final class AutoContextRecovering implements AutoContextRecoveringInterface
{
    private const RETRY_COUNT = 10;

    /** @var ContextInterface<T>|null */
    private ?ContextInterface $context = null;

    private ?\Closure $on_recover_callback = null;

    /**
     * @param \Closure():ContextInterface<T> $context_factory
     */
    public function __construct(
        private readonly \Closure $context_factory,
    ) {
    }

    public function recreateContext(): void
    {
        if (!is_null($this->context) and $this->context->isRunning()) {
            $this->context->stop();
        }
        $this->context = null;
    }

    public function getContext(): ContextInterface
    {
        if (is_null($this->context)) {
            $this->context = $this->context_factory->__invoke();
            if (!is_null($this->on_recover_callback)) {
                $this->on_recover_callback->__invoke();
            }
        }
        return $this->context;
    }

    public function withAutoRecover(
        \Closure $callback,
        string $log_message_on_retry,
    ): mixed {
        for ($i = 0; $i < self::RETRY_COUNT; $i++) {
            try {
                return $callback($this->getContext()->getProtocol());
            } catch (ContextException $e) {
                Log::info(
                    $log_message_on_retry,
                    [
                        'exception' => $e,
                    ]
                );
                $this->recreateContext();
            }
        }
        assert($e instanceof \Throwable);
        throw $e;
    }

    public function onRecover(\Closure $callback): void
    {
        $this->on_recover_callback = $callback;
    }
}
