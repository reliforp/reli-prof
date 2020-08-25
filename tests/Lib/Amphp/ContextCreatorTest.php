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

namespace PhpProfiler\Lib\Amphp;

use Amp\Parallel\Sync\Channel;
use PHPUnit\Framework\TestCase;

class ContextCreatorTest extends TestCase
{
    public function testCreate(): void
    {
        $creator = new ContextCreator('di_config');
        $worker_protocol_class = new class implements MessageProtocolInterface {
            public static function createFromChannel(Channel $channel): self
            {
                return new self();
            }
        };
        $controller_protocol_class = $worker_protocol_class;
        $context = $creator->create(
            WorkerEntryPointInterface::class,
            get_class($worker_protocol_class),
            get_class($controller_protocol_class),
        );
        $this->assertInstanceOf(Context::class, $context);
    }
}
