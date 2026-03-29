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

namespace Reli\Inspector\Watch\Daemon\Protocol\Message;

use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;

final class WatchAttachMessage
{
    public function __construct(
        public readonly TargetProcessDescriptor $process_descriptor,
    ) {
    }
}
