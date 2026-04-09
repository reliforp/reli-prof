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

namespace Reli\Converter\BinaryTrace;

use Reli\BaseTestCase;
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;

final class FoldedStacksFormatterTest extends BaseTestCase
{
    public function testBasicFolding(): void
    {
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('leaf', '/leaf.php', 10),
                new ParsedCallFrame('main', '/index.php', 1),
            ),
        ];

        $formatter = new FoldedStacksFormatter();
        $result = $formatter->format($traces);

        $this->assertSame("main /index.php:1;leaf /leaf.php:10 1\n", $result);
    }

    public function testAggregation(): void
    {
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('func_a', '/a.php', 1),
                new ParsedCallFrame('main', '/index.php', 5),
            ),
            new ParsedCallTrace(
                new ParsedCallFrame('func_a', '/a.php', 1),
                new ParsedCallFrame('main', '/index.php', 5),
            ),
            new ParsedCallTrace(
                new ParsedCallFrame('func_b', '/b.php', 2),
                new ParsedCallFrame('main', '/index.php', 5),
            ),
        ];

        $formatter = new FoldedStacksFormatter();
        $result = $formatter->format($traces);
        $lines = array_filter(explode("\n", $result));

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('main /index.php:5;func_a /a.php:1 2', $result);
        $this->assertStringContainsString('main /index.php:5;func_b /b.php:2 1', $result);
    }

    public function testEmptyTrace(): void
    {
        $traces = [new ParsedCallTrace()];

        $formatter = new FoldedStacksFormatter();
        $result = $formatter->format($traces);

        $this->assertSame(" 1\n", $result);
    }
}
