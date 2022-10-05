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

interface ContextCreatorInterface
{
    /**
     * @template TWorkerProtocol of MessageProtocolInterface
     * @template TControllerProtocol of MessageProtocolInterface
     * @param class-string<WorkerEntryPointInterface> $entry_point_class
     * @param class-string<TWorkerProtocol> $worker_protocol_class
     * @param class-string<TControllerProtocol> $controller_protocol_class
     * @return ContextInterface<TControllerProtocol>
     */
    public function create(
        string $entry_point_class,
        string $worker_protocol_class,
        string $controller_protocol_class
    ): ContextInterface;
}
