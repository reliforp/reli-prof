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

namespace Reli\Converter;

use Reli\BaseTestCase;

class ParsedCallTraceTest extends BaseTestCase
{
    public function testConstructWithFrames(): void
    {
        $frame1 = new ParsedCallFrame('func_a', 'file_a.php', 1);
        $frame2 = new ParsedCallFrame('func_b', 'file_b.php', 2);
        $trace = new ParsedCallTrace($frame1, $frame2);

        $this->assertCount(2, $trace->call_frames);
        $this->assertSame($frame1, $trace->call_frames[0]);
        $this->assertSame($frame2, $trace->call_frames[1]);
    }

    public function testConstructWithNoFrames(): void
    {
        $trace = new ParsedCallTrace();
        $this->assertCount(0, $trace->call_frames);
    }
}
