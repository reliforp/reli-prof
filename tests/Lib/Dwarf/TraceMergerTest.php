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

    public function testMergeMultiplePhpFramesIcallInlined(): void
    {
        // <main> calls sleep_wrapper (internal), ICALL handler inlined into execute_ex.
        // Only 1 VM boundary (execute_ex), but 2 PHP frames.
        // Both PHP frames should be placed before execute_ex.
        $native = new NativeTrace(
            new NativeFrame(0x1000, 'zif_sleep', 'php', 0),
            new NativeFrame(0x2000, 'execute_ex', 'php', 0),
            new NativeFrame(0x3000, 'zend_execute', 'php', 0),
        );
        $php = new CallTrace(
            new CallFrame('', 'sleep_wrapper', '/app/test.php', null),
            new CallFrame('', 'main', '/app/test.php', null),
        );

        $merged = $this->merger->merge($native, $php);

        // zif_sleep [native], sleep_wrapper [php], main [php], execute_ex [native], zend_execute [native]
        $this->assertCount(5, $merged->frames);
        $this->assertSame('zif_sleep', $merged->frames[0]->nativeFrame->symbol_name);
        $this->assertSame('sleep_wrapper', $merged->frames[1]->phpFrame->function_name);
        $this->assertSame('main', $merged->frames[2]->phpFrame->function_name);
        $this->assertSame('execute_ex', $merged->frames[3]->nativeFrame->symbol_name);
        $this->assertSame('zend_execute', $merged->frames[4]->nativeFrame->symbol_name);
    }

    public function testMergeMultiplePhpFramesIcallVisible(): void
    {
        // <main> calls sleep_wrapper (internal), ICALL handler visible in native trace.
        // 2 VM boundaries (ICALL + execute_ex), 2 PHP frames → 1:1 match.
        $native = new NativeTrace(
            new NativeFrame(0x1000, 'zif_sleep', 'php', 0),
            new NativeFrame(0x2000, 'ZEND_DO_ICALL_SPEC_RETVAL_UNUSED_HANDLER', 'php', 0),
            new NativeFrame(0x3000, 'execute_ex', 'php', 0),
            new NativeFrame(0x4000, 'zend_execute', 'php', 0),
        );
        $php = new CallTrace(
            new CallFrame('', 'sleep_wrapper', '/app/test.php', null),
            new CallFrame('', 'main', '/app/test.php', null),
        );

        $merged = $this->merger->merge($native, $php);

        // zif_sleep, sleep_wrapper [php], ICALL, main [php], execute_ex, zend_execute
        $this->assertCount(6, $merged->frames);
        $this->assertSame('zif_sleep', $merged->frames[0]->nativeFrame->symbol_name);
        $this->assertSame('sleep_wrapper', $merged->frames[1]->phpFrame->function_name);
        $this->assertSame('ZEND_DO_ICALL_SPEC_RETVAL_UNUSED_HANDLER', $merged->frames[2]->nativeFrame->symbol_name);
        $this->assertSame('main', $merged->frames[3]->phpFrame->function_name);
        $this->assertSame('execute_ex', $merged->frames[4]->nativeFrame->symbol_name);
        $this->assertSame('zend_execute', $merged->frames[5]->nativeFrame->symbol_name);
    }

    public function testMergeDeepCallChain(): void
    {
        // <main> → foo() → bar() → strlen() (internal, handler inlined)
        // 3 execute_ex in native, 4 PHP frames
        $native = new NativeTrace(
            new NativeFrame(0x1000, 'zif_strlen', 'php', 0),
            new NativeFrame(0x2000, 'execute_ex', 'php', 0),  // bar
            new NativeFrame(0x3000, 'execute_ex', 'php', 0),  // foo
            new NativeFrame(0x4000, 'execute_ex', 'php', 0),  // main
            new NativeFrame(0x5000, 'zend_execute', 'php', 0),
        );
        $php = new CallTrace(
            new CallFrame('', 'strlen', '/app/test.php', null),
            new CallFrame('', 'bar', '/app/test.php', null),
            new CallFrame('', 'foo', '/app/test.php', null),
            new CallFrame('', 'main', '/app/test.php', null),
        );

        $merged = $this->merger->merge($native, $php);

        // strlen (excess) + bar before first execute_ex, then foo, main before subsequent ones
        $this->assertCount(9, $merged->frames);
        $this->assertSame('zif_strlen', $merged->frames[0]->nativeFrame->symbol_name);
        $this->assertSame('strlen', $merged->frames[1]->phpFrame->function_name);
        $this->assertSame('bar', $merged->frames[2]->phpFrame->function_name);
        $this->assertSame('execute_ex', $merged->frames[3]->nativeFrame->symbol_name);
        $this->assertSame('foo', $merged->frames[4]->phpFrame->function_name);
        $this->assertSame('execute_ex', $merged->frames[5]->nativeFrame->symbol_name);
        $this->assertSame('main', $merged->frames[6]->phpFrame->function_name);
        $this->assertSame('execute_ex', $merged->frames[7]->nativeFrame->symbol_name);
        $this->assertSame('zend_execute', $merged->frames[8]->nativeFrame->symbol_name);
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
