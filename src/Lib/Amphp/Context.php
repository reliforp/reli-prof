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

namespace Reli\Lib\Amphp;

use Amp\Parallel\Context\Context as AmphpContext;

/**
 * @template-covariant T of MessageProtocolInterface
 * @implements ContextInterface<T>
 */
final class Context implements ContextInterface
{
    /** @param T $protocol_interface */
    public function __construct(
        private AmphpContext $amphp_context,
        private object $protocol_interface
    ) {
    }

    public function start(): void
    {
        ;
    }

    public function isRunning(): bool
    {
        return !$this->amphp_context->isClosed();
    }

    public function stop(): void
    {
        $this->amphp_context->close();
    }

    /** @return T */
    public function getProtocol(): object
    {
        return $this->protocol_interface;
    }
}
