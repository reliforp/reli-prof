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

/** @template-covariant T of MessageProtocolInterface */
interface ContextInterface
{
    public function start(): void;

    public function stop(): void;

    public function isRunning(): bool;

    /** @return T */
    public function getProtocol(): object;
}
