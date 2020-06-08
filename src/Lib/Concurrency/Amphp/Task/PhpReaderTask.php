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

namespace PhpProfiler\Lib\Concurrency\Amphp\Task;

use Amp\Parallel\Sync\Channel;
use Generator;
use PhpProfiler\Command\Inspector\GetTraceCommand;

final class PhpReaderTask
{
    private Channel $channel;
    private GetTraceCommand $get_trace_command;

    public function __construct(Channel $channel, GetTraceCommand $get_trace_command)
    {
        $this->channel = $channel;
        $this->get_trace_command = $get_trace_command;
    }

    public function run(): Generator
    {
        /** @var int $pid */
        $pid = yield $this->channel->receive();
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/../../../../../php-profiler',
                'inspector:trace',
                '-p',
                $pid
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );
        stream_set_blocking($pipes[1], false);
        for ($status = proc_get_status($process); $status['running']; $status = proc_get_status($process)) {
            yield $this->channel->send(stream_get_contents($pipes[1]));
        }
    }
}
