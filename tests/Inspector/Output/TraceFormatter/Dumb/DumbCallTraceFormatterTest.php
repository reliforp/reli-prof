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

namespace PhpProfiler\Inspector\Output\TraceFormatter\Dumb;

use PhpProfiler\Lib\PhpInternals\Types\Zend\Opline;
use PhpProfiler\Lib\PhpProcessReader\CallFrame;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;
use PHPUnit\Framework\TestCase;

class DumbCallTraceFormatterTest extends TestCase
{
    /** @dataProvider dataProvider */
    public function testFormat(string $expects, CallTrace $call_trace): void
    {
        $formatter = new DumbCallTraceFormatter(new DumbCallFrameFormatter());
        $this->assertSame($expects, $formatter->format($call_trace));
    }

    public function dataProvider(): array
    {
        return [
            'one_function_only_without_opline' => [
                'test test_file.php(-1)',
                new CallTrace(
                    new CallFrame('', 'test', 'test_file.php', null)
                ),
            ],
            'one_function_only_with_opline' => [
                'test test_file.php(1)',
                new CallTrace(
                    new CallFrame(
                        '',
                        'test',
                        'test_file.php',
                        new Opline(
                            1,
                            1,
                            1,
                            1,
                            1,
                            1,
                            1,
                            1,
                            1,
                        )
                    )
                ),
            ],
            'one_method_only_without_opline' => [
                'ClassName::test test_file.php(-1)',
                new CallTrace(
                    new CallFrame('ClassName', 'test', 'test_file.php', null)
                ),
            ],
            'one_methods_without_opline' => [
                <<<TRACE
                ClassName1::test1 test_file1.php(-1)
                ClassName2::test2 test_file2.php(-1)
                TRACE,
                new CallTrace(
                    new CallFrame('ClassName1', 'test1', 'test_file1.php', null),
                    new CallFrame('ClassName2', 'test2', 'test_file2.php', null),
                ),
            ],
        ];
    }
}
