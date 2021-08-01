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

namespace PhpProfiler\Inspector\Output\TraceFormatter\Templated;

use PhpProfiler\Lib\PhpProcessReader\CallFrame;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;
use PHPUnit\Framework\TestCase;

class TemplatedCallTraceFormatterTest extends TestCase
{
    public function testFormat(): void
    {
        $path_resolver = \Mockery::mock(TemplatePathResolverInterface::class);
        $path_resolver->expects()->resolve('test')->andReturns(__DIR__ . '/templates/test.php');
        $formatter = new TemplatedCallTraceFormatter(
            $path_resolver,
            'test'
        );
        $call_trace = new CallTrace(
            new CallFrame('TestClass', 'test', 'test_file.php', null)
        );
        $this->assertSame('TestClass::test', $formatter->format($call_trace));
    }
}
