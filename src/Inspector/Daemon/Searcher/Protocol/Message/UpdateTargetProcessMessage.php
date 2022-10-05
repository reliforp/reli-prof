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

namespace PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message;

use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessList;

final class UpdateTargetProcessMessage
{
    public function __construct(
        public TargetProcessList $target_process_list
    ) {
    }
}
