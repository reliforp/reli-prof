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

namespace PhpProfiler\Inspector\Daemon\Searcher\Worker;

use Amp\Success;
use Mockery;
use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use PhpProfiler\Inspector\Daemon\Dispatcher\TargetProcessList;
use PhpProfiler\Inspector\Daemon\Searcher\Protocol\PhpSearcherWorkerProtocolInterface;
use PhpProfiler\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PhpProfiler\Lib\Process\Search\ProcessSearcherInterface;
use PHPUnit\Framework\TestCase;

class PhpSearcherEntryPointTest extends TestCase
{
    public function testRun()
    {
        $protcol = Mockery::mock(PhpSearcherWorkerProtocolInterface::class);
        $protcol->expects()->receiveTargetPhpSettings()->andReturns(new Success(1))->once();
        $protcol->shouldReceive('sendUpdateTargetProcess')
            ->withArgs(
                function (UpdateTargetProcessMessage $message) {
                    $diff = $message->target_process_list->getDiff(
                        new TargetProcessList(
                            new TargetProcessDescriptor(1, 0, 0, ZendTypeReader::V80),
                            new TargetProcessDescriptor(2, 0, 0, ZendTypeReader::V80),
                            new TargetProcessDescriptor(3, 0, 0, ZendTypeReader::V80),
                        )
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
        $process_descriptor_retriever = Mockery::mock(ProcessDescriptorRetriever::class);

        $entry_point = new PhpSearcherEntryPoint(
            $protcol,
            $process_searcher,
            $process_descriptor_retriever,
        );

        $generator = $entry_point->run();

        $promise = $generator->current();
        $this->assertInstanceOf(Success::class, $promise);
        $promise->onResolve(function ($error, $value) use (&$result) {
            $result = $value;
        });
        $this->assertSame(1, $result);

        $promise = $generator->send(
            new TargetPhpSettingsMessage(
                'regex_to_search_process',
                new TargetPhpSettings(),
            )
        );
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
