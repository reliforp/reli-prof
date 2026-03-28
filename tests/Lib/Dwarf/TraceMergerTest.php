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

namespace Reli\Lib\Dwarf;

use Reli\BaseTestCase;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Reli\Lib\PhpProcessReader\CallTraceReader\TraceMerger;

class TraceMergerTest extends BaseTestCase
{
    private TraceMerger $merger;

    protected function setUp(): void
    {
        $this->merger = new TraceMerger();
    }

    public function testMergeNativeOnly(): void
    {
        $native = new NativeTrace(
            new NativeFrame(0x1000, 'main', 'php', 0),
            new NativeFrame(0x2000, '__libc_start_main', 'libc.so.6', 0),
        );
        $php = new CallTrace();

        $merged = $this->merger->merge($native, $php);
        $this->assertCount(2, $merged->frames);
        $this->assertTrue($merged->frames[0]->isNative());
        $this->assertTrue($merged->frames[1]->isNative());
    }

    public function testMergeInterleaved(): void
    {
        $native = new NativeTrace(
            new NativeFrame(0x1000, 'some_internal', 'php', 0),
            new NativeFrame(0x2000, 'execute_ex', 'php', 0),
            new NativeFrame(0x3000, 'zend_execute_script', 'php', 0),
        );
        $php = new CallTrace(
            new CallFrame('', 'myFunction', '/app/test.php', null),
        );

        $merged = $this->merger->merge($native, $php);

        // Should have: some_internal [native], myFunction [php], execute_ex [native], zend_execute_script [native]
        // PHP frame is placed BEFORE its VM boundary (execute_ex dispatches/runs myFunction)
        $this->assertCount(4, $merged->frames);
        $this->assertTrue($merged->frames[0]->isNative());
        $this->assertSame('some_internal', $merged->frames[0]->nativeFrame->symbol_name);
        $this->assertTrue($merged->frames[1]->isPhp());
        $this->assertSame('myFunction', $merged->frames[1]->phpFrame->function_name);
        $this->assertTrue($merged->frames[2]->isNative());
        $this->assertSame('execute_ex', $merged->frames[2]->nativeFrame->symbol_name);
        $this->assertTrue($merged->frames[3]->isNative());
    }

    public function testMergeMultiplePhpFrames(): void
    {
        $native = new NativeTrace(
            new NativeFrame(0x1000, 'zif_sleep', 'php', 0),
            new NativeFrame(0x2000, 'execute_ex', 'php', 0),
            new NativeFrame(0x3000, 'zend_execute', 'php', 0),
        );
        // PHP frames: innermost (current) first in call_frames
        $php = new CallTrace(
            new CallFrame('', 'sleep_wrapper', '/app/test.php', null),
            new CallFrame('', 'main', '/app/test.php', null),
        );

        $merged = $this->merger->merge($native, $php);

        // PHP frames are placed BEFORE their VM boundaries (inner side):
        // zif_sleep [native], sleep_wrapper [php], execute_ex [native], main [php], zend_execute [native]
        $this->assertCount(5, $merged->frames);
        $this->assertSame('zif_sleep', $merged->frames[0]->nativeFrame->symbol_name);
        $this->assertSame('sleep_wrapper', $merged->frames[1]->phpFrame->function_name);
        $this->assertSame('execute_ex', $merged->frames[2]->nativeFrame->symbol_name);
        $this->assertSame('main', $merged->frames[3]->phpFrame->function_name);
        $this->assertSame('zend_execute', $merged->frames[4]->nativeFrame->symbol_name);
    }

    public function testMergeEmptyNative(): void
    {
        $native = new NativeTrace();
        $php = new CallTrace(
            new CallFrame('', 'test', '/app/test.php', null),
        );

        $merged = $this->merger->merge($native, $php);
        // Remaining PHP frames should be appended
        $this->assertCount(1, $merged->frames);
        $this->assertTrue($merged->frames[0]->isPhp());
    }

    public function testGetPhpOnlyTrace(): void
    {
        $native = new NativeTrace(
            new NativeFrame(0x1000, 'execute_ex', 'php', 0),
        );
        $php = new CallTrace(
            new CallFrame('MyClass', 'doWork', '/app/src/MyClass.php', null),
        );

        $merged = $this->merger->merge($native, $php);
        $phpOnly = $merged->getPhpOnlyTrace();
        $this->assertCount(1, $phpOnly->call_frames);
        $this->assertSame('MyClass', $phpOnly->call_frames[0]->class_name);
    }
}
