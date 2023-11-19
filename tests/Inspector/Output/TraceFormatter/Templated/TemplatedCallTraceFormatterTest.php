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

namespace Reli\Inspector\Output\TraceFormatter\Templated;

use Reli\BaseTestCase;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;

class TemplatedCallTraceFormatterTest extends BaseTestCase
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
