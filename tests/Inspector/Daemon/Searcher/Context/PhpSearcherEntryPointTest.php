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

namespace PhpProfiler\Inspector\Daemon\Searcher\Context;

use Amp\Parallel\Sync\Channel;
use Amp\Success;
use Mockery;
use PhpProfiler\Inspector\Daemon\Dispatcher\Message\UpdateTargetProcessMessage;
use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessList;
use PhpProfiler\Lib\Process\Search\ProcessSearcherInterface;
use PHPUnit\Framework\TestCase;

class PhpSearcherEntryPointTest extends TestCase
{
    public function testRun()
    {
        $channel = Mockery::mock(Channel::class);
        $channel->expects()->receive()->andReturns(new Success(1))->once();
        $channel->shouldReceive('send')
            ->withArgs(
                function (UpdateTargetProcessMessage $message) {
                    $diff = $message->target_process_list->getDiff(
                        new TargetProcessList(1, 2, 3)
                    )->getArray();
                    $this->assertSame([], $diff);
                    return true;
                }
            )
            ->andReturns(
                new Success(2),
                new Success(3),
            )
            ->once();

        $process_searcher = Mockery::mock(ProcessSearcherInterface::class);
        $process_searcher->expects()->searchByRegex('regex_to_search_process');

        $entry_point = new PhpSearcherEntryPoint($process_searcher);

        $generator = $entry_point->run($channel);

        $promise = $generator->current();
        $this->assertInstanceOf(Success::class, $promise);
        $promise->onResolve(function ($error, $value) use (&$result) {
            $result = $value;
        });
        $this->assertSame(1, $result);

        $promise = $generator->send('regex_to_search_process');
        $this->assertInstanceOf(Success::class, $promise);
        $promise->onResolve(function ($error, $value) use (&$result) {
            $result = $value;
        });
        $this->assertSame(2, $result);

        $promise = $generator->send(null);
        $this->assertInstanceOf(Success::class, $promise);
        $promise->onResolve(function ($error, $value) use (&$result) {
            $result = $value;
        });
        $this->assertSame(3, $result);
    }
}
