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

namespace PhpProfiler\Inspector\Daemon\Dispatcher;

use PhpProfiler\Lib\PhpInternals\ZendTypeReader;
use PHPUnit\Framework\TestCase;

class TargetProcessListTest extends TestCase
{
    public function testPickOne(): void
    {
        $picked = [];
        $target_process_list = new TargetProcessList(
            new TargetProcessDescriptor(1, 0, ZendTypeReader::V80),
            new TargetProcessDescriptor(2, 0, ZendTypeReader::V80),
            new TargetProcessDescriptor(3, 0, ZendTypeReader::V80),
        );
        $picked[] = $target_process_list->pickOne()->pid;
        $picked[] = $target_process_list->pickOne()->pid;
        $picked[] = $target_process_list->pickOne()->pid;
        sort($picked);
        $this->assertSame(
            [1, 2, 3],
            $picked
        );
        $this->assertNull($target_process_list->pickOne());
    }

    public function testPutOne(): void
    {
        $target_process_list = new TargetProcessList();
        $this->assertNull($target_process_list->pickOne());
        $target_process_list->putOne(
            new TargetProcessDescriptor(1, 0, ZendTypeReader::V80),
        );
        $this->assertSame(1, $target_process_list->pickOne()->pid);
        $target_process_list->putOne(
            new TargetProcessDescriptor(1, 0, ZendTypeReader::V80),
        );
        $target_process_list->putOne(
            new TargetProcessDescriptor(2, 0, ZendTypeReader::V80),
        );
        $picked = [];
        $picked[] = $target_process_list->pickOne()->pid;
        $picked[] = $target_process_list->pickOne()->pid;
        sort($picked);
        $this->assertSame([1, 2], $picked);
    }

    public function testGetArray(): void
    {
        $target_process_list = new TargetProcessList(
            new TargetProcessDescriptor(1, 0, ZendTypeReader::V80),
            new TargetProcessDescriptor(2, 0, ZendTypeReader::V80),
            new TargetProcessDescriptor(3, 0, ZendTypeReader::V80),
        );
        $this->assertSame(
            [1, 2, 3],
            array_map(
                fn (TargetProcessDescriptor $process_descriptor) => $process_descriptor->pid,
                $target_process_list->getArray(),
            )
        );
        $target_process_list->putOne(
            new TargetProcessDescriptor(4, 0, ZendTypeReader::V80),
        );
        $this->assertSame(
            [1, 2, 3, 4],
            array_map(
                fn (TargetProcessDescriptor $process_descriptor) => $process_descriptor->pid,
                $target_process_list->getArray(),
            )
        );
        $picked = $target_process_list->pickOne();
        $this->assertSame(
            array_diff([1, 2, 3, 4], [$picked->pid]),
            array_map(
                fn (TargetProcessDescriptor $process_descriptor) => $process_descriptor->pid,
                $target_process_list->getArray(),
            )
        );
    }

    public function testGetDiff(): void
    {
        $target_process_list_1 = new TargetProcessList(
            new TargetProcessDescriptor(1, 0, ZendTypeReader::V80),
            new TargetProcessDescriptor(2, 0, ZendTypeReader::V80),
            new TargetProcessDescriptor(3, 0, ZendTypeReader::V80),
            new TargetProcessDescriptor(4, 0, ZendTypeReader::V80),
        );
        $target_process_list_2 = new TargetProcessList(
            new TargetProcessDescriptor(2, 0, ZendTypeReader::V80),
            new TargetProcessDescriptor(3, 0, ZendTypeReader::V80),
        );
        $diff = $target_process_list_1->getDiff($target_process_list_2);
        $this->assertSame(
            [1, 4],
            array_map(
                fn (TargetProcessDescriptor $process_descriptor) => $process_descriptor->pid,
                $diff->getArray(),
            )
        );
    }
}
