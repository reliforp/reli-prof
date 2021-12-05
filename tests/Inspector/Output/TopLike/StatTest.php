<?php

/**
 * This file is part of the sj-i/ package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Output\TopLike;

use PhpProfiler\Lib\PhpProcessReader\CallFrame;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;
use PHPUnit\Framework\TestCase;

class StatTest extends TestCase
{
    public function testAddTrace()
    {
        $stat = new Stat();
        $stat->addTrace(
            new CallTrace(
                new CallFrame(
                    'ClassName1',
                    'functionName1',
                    'file1',
                    null
                )
            )
        );
        $this->assertSame(1, $stat->sample_count);
        $this->assertEquals(
            new FunctionEntry(
                name: 'ClassName1::functionName1',
                file: 'file1',
                lineno: -1,
                count_exclusive: 1,
                count_inclusive: 1,
                total_count_exclusive: 0,
                total_count_inclusive: 0,
                percent_exclusive: 0
            ),
            $stat->function_entries['ClassName1::functionName1']
        );
        $stat->addTrace(
            new CallTrace(
                new CallFrame(
                    'ClassName2',
                    'functionName2',
                    'file2',
                    null
                ),
                new CallFrame(
                    'ClassName1',
                    'functionName1',
                    'file1',
                    null
                )
            )
        );
        $this->assertSame(2, $stat->sample_count);
        $this->assertEquals(
            new FunctionEntry(
                name: 'ClassName1::functionName1',
                file: 'file1',
                lineno: -1,
                count_exclusive: 1,
                count_inclusive: 2,
                total_count_exclusive: 0,
                total_count_inclusive: 0,
                percent_exclusive: 0
            ),
            $stat->function_entries['ClassName1::functionName1']
        );
        $this->assertEquals(
            new FunctionEntry(
                name: 'ClassName2::functionName2',
                file: 'file2',
                lineno: -1,
                count_exclusive: 1,
                count_inclusive: 1,
                total_count_exclusive: 0,
                total_count_inclusive: 0,
                percent_exclusive: 0
            ),
            $stat->function_entries['ClassName2::functionName2']
        );
        $stat->addTrace(
            new CallTrace(
                new CallFrame(
                    'ClassName1',
                    'functionName1',
                    'file1',
                    null
                ),
                new CallFrame(
                    'ClassName2',
                    'functionName2',
                    'file2',
                    null
                ),
            )
        );
        $this->assertSame(3, $stat->sample_count);
        $this->assertEquals(
            new FunctionEntry(
                name: 'ClassName1::functionName1',
                file: 'file1',
                lineno: -1,
                count_exclusive: 2,
                count_inclusive: 3,
                total_count_exclusive: 0,
                total_count_inclusive: 0,
                percent_exclusive: 0
            ),
            $stat->function_entries['ClassName1::functionName1']
        );
        $this->assertEquals(
            new FunctionEntry(
                name: 'ClassName2::functionName2',
                file: 'file2',
                lineno: -1,
                count_exclusive: 1,
                count_inclusive: 2,
                total_count_exclusive: 0,
                total_count_inclusive: 0,
                percent_exclusive: 0
            ),
            $stat->function_entries['ClassName2::functionName2']
        );
    }

    public function testSort()
    {
        $entry1 = new FunctionEntry(
            name: 'ClassName1::functionName1',
            file: 'file1',
            lineno: -1,
            count_exclusive: 1,
            count_inclusive: 2,
            total_count_exclusive: 0,
            total_count_inclusive: 0,
            percent_exclusive: 0
        );
        $entry2 = new FunctionEntry(
            name: 'ClassName2::functionName2',
            file: 'file2',
            lineno: -1,
            count_exclusive: 1,
            count_inclusive: 1,
            total_count_exclusive: 1,
            total_count_inclusive: 1,
            percent_exclusive: 0
        );
        $entry3 = new FunctionEntry(
            name: 'ClassName3::functionName3',
            file: 'file3',
            lineno: -1,
            count_exclusive: 1,
            count_inclusive: 1,
            total_count_exclusive: 1,
            total_count_inclusive: 2,
            percent_exclusive: 0
        );
        $entry4 = new FunctionEntry(
            name: 'ClassName4::functionName4',
            file: 'file4',
            lineno: -1,
            count_exclusive: 1,
            count_inclusive: 1,
            total_count_exclusive: 2,
            total_count_inclusive: 1,
            percent_exclusive: 0
        );
        $entry5 = new FunctionEntry(
            name: 'ClassName5::functionName5',
            file: 'file5',
            lineno: -1,
            count_exclusive: 2,
            count_inclusive: 2,
            total_count_exclusive: 2,
            total_count_inclusive: 2,
            percent_exclusive: 0
        );
        $stat = new Stat(
            [
                'ClassName2::functionName2' => $entry2,
                'ClassName1::functionName1' => $entry1,
                'ClassName3::functionName3' => $entry3,
                'ClassName4::functionName4' => $entry4,
                'ClassName5::functionName5' => $entry5,
            ]
        );
        $entries = $stat->function_entries;
        $this->assertEquals(
            $entry2, current($entries)
        );
        $this->assertEquals(
            $entry1, next($entries)
        );
        $this->assertEquals(
            $entry3, next($entries)
        );
        $this->assertEquals(
            $entry4, next($entries)
        );
        $this->assertEquals(
            $entry5, next($entries)
        );
        $stat->sort();
        $entries = $stat->function_entries;
        $this->assertEquals(
            $entry5, current($entries)
        );
        $this->assertEquals(
            $entry1, next($entries)
        );
        $this->assertEquals(
            $entry4, next($entries)
        );
        $this->assertEquals(
            $entry3, next($entries)
        );
        $this->assertEquals(
            $entry2, next($entries)
        );
    }

    public function testCalculateEntryTotals()
    {
        $stat = new Stat([
            'ClassName1::functionName1' => new FunctionEntry(
                name: 'ClassName1::functionName1',
                file: 'file1',
                lineno: -1,
                count_exclusive: 1,
                count_inclusive: 1,
                total_count_exclusive: 0,
                total_count_inclusive: 0,
                percent_exclusive: 0
            ),
            'ClassName2::functionName2' => new FunctionEntry(
                name: 'ClassName2::functionName2',
                file: 'file2',
                lineno: -1,
                count_exclusive: 2,
                count_inclusive: 3,
                total_count_exclusive: 0,
                total_count_inclusive: 0,
                percent_exclusive: 0
            ),
        ]);
        $stat->sample_count = 3;
        $stat->calculateEntryTotals();
        $entry1 = $stat->function_entries['ClassName1::functionName1'];
        $entry2 = $stat->function_entries['ClassName2::functionName2'];
        $this->assertSame(1, $entry1->total_count_exclusive);
        $this->assertSame(1, $entry1->total_count_inclusive);
        $this->assertSame(100 * 1 / 3, $entry1->percent_exclusive);
        $this->assertSame(2, $entry2->total_count_exclusive);
        $this->assertSame(3, $entry2->total_count_inclusive);
        $this->assertSame(100 * 2 / 3, $entry2->percent_exclusive);
    }

    public function testUpdateTotalSampleCount()
    {
        $stat = new Stat();
        $this->assertSame(0, $stat->total_count);
        $stat->sample_count = 3;
        $stat->updateTotalSampleCount();
        $this->assertSame(3, $stat->total_count);
        $stat->clearCurrentSamples();
        $stat->updateTotalSampleCount();
        $this->assertSame(3, $stat->total_count);
        $stat->sample_count = 3;
        $stat->updateTotalSampleCount();
        $this->assertSame(6, $stat->total_count);
    }

}
