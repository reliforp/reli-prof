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

namespace PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message;

use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessList;

final class UpdateTargetProcessMessage
{
    public TargetProcessList $target_process_list;

    public function __construct(TargetProcessList $target_process_list)
    {
        $this->target_process_list = $target_process_list;
    }
}
