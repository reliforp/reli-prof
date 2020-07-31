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

use PHPUnit\Framework\TestCase;

class TargetProcessListTest extends TestCase
{
    public function testPickOne(): void
    {
        $picked = [];
        $target_process_list = new TargetProcessList(1, 2, 3);
        $picked[] = $target_process_list->pickOne();
        $picked[] = $target_process_list->pickOne();
        $picked[] = $target_process_list->pickOne();
        sort($picked);
        $this->assertSame([1, 2, 3], $picked);
        $this->assertSame(null, $target_process_list->pickOne());
    }

    public function testPutOne(): void
    {
        $target_process_list = new TargetProcessList();
        $this->assertSame(null, $target_process_list->pickOne());
        $target_process_list->putOne(1);
        $this->assertSame(1, $target_process_list->pickOne());
        $target_process_list->putOne(1);
        $target_process_list->putOne(2);
        $picked = [];
        $picked[] = $target_process_list->pickOne();
        $picked[] = $target_process_list->pickOne();
        sort($picked);
        $this->assertSame([1, 2], $picked);
    }

    public function testGetArray(): void
    {
        $target_process_list = new TargetProcessList(1, 2, 3);
        $this->assertSame([1, 2, 3], $target_process_list->getArray());
        $target_process_list->putOne(4);
        $this->assertSame([1, 2, 3, 4], $target_process_list->getArray());
        $picked = $target_process_list->pickOne();
        $this->assertSame(array_diff([1, 2, 3, 4], [$picked]), $target_process_list->getArray());
    }

    public function testGetDiff(): void
    {
        $target_process_list_1 = new TargetProcessList(1, 2, 3, 4);
        $target_process_list_2 = new TargetProcessList(2, 3);
        $diff = $target_process_list_1->getDiff($target_process_list_2);
        $this->assertSame([1, 4], $diff->getArray());
    }
}
