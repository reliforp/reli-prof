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

namespace Reli\Inspector\Output\TopLike;

use Reli\Lib\DateTime\FixedClock;
use Reli\Lib\PhpProcessReader\CallTrace;
use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertSame;

class TopLikeFormatterTest extends TestCase
{
    public function testFormat()
    {
        $now = new \DateTimeImmutable();
        $formatter = new TopLikeFormatter(
            'regex',
            $outputter = new class () implements Outputter {
                public int $call_count = 0;
                public function display(string $trace_target, Stat $stat): void
                {
                    assertSame('regex', $trace_target);
                    $this->call_count++;
                }
            },
            $clock = new FixedClock($now)
        );
        $this->assertSame(0, $outputter->call_count);
        $formatter->format(new CallTrace());

        $clock->update($now->modify('+1 second'));
        $formatter->format(new CallTrace());
        $this->assertSame(1, $outputter->call_count);
    }
}
