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

use function Amp\Parallel\Context\startContext;

final class ContextCreator implements ContextCreatorInterface
{
    public const ENTRY_SCRIPT = __DIR__ . '/worker-entry.php';

    public function __construct(
        private string $di_config_file
    ) {
    }

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
    ): ContextInterface {
        $amphp_cotext = startContext([
            self::ENTRY_SCRIPT,
            $entry_point_class,
            $worker_protocol_class,
            $this->di_config_file,
        ]);

        /** @var ContextInterface<TControllerProtocol> */
        return new Context(
            $amphp_cotext,
            $controller_protocol_class::createFromChannel($amphp_cotext)
        );
    }
}
