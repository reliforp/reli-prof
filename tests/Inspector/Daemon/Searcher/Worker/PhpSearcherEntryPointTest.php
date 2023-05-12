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

namespace Reli\Inspector\Daemon\Searcher\Worker;

use Mockery;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessDescriptor;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\TargetPhpSettingsMessage;
use Reli\Inspector\Daemon\Searcher\Protocol\Message\UpdateTargetProcessMessage;
use Reli\Inspector\Daemon\Dispatcher\TargetProcessList;
use Reli\Inspector\Daemon\Searcher\Protocol\PhpSearcherWorkerProtocolInterface;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\Loop\LoopCondition\OnlyOnceCondition;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\ProcFileSystem\ThreadEnumerator;
use Reli\Lib\Process\Search\ProcessSearcherInterface;
use PHPUnit\Framework\TestCase;

class PhpSearcherEntryPointTest extends TestCase
{
    public function testRun()
    {
        $target_php_settings = new TargetPhpSettingsMessage(
            'regex_to_search_process',
            new TargetPhpSettings(),
            getmypid(),
        );
        $protcol = Mockery::mock(PhpSearcherWorkerProtocolInterface::class);
        $protcol->expects()->receiveTargetPhpSettings()->andReturns($target_php_settings)->once();
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
            ->once();
        $process_searcher = Mockery::mock(ProcessSearcherInterface::class);
        $process_searcher->expects()->searchByRegex('regex_to_search_process');
        $process_descriptor_retriever = Mockery::mock(ProcessDescriptorRetriever::class);

        $entry_point = new PhpSearcherEntryPoint(
            $protcol,
            $process_searcher,
            $process_descriptor_retriever,
            new ThreadEnumerator(),
            new OnlyOnceCondition(),
        );

        $entry_point->run();
    }
}
