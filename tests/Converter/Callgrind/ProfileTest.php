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

namespace Reli\Converter\Callgrind;

use Reli\BaseTestCase;
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;

class ProfileTest extends BaseTestCase
{
    public function testAddSingleFrameTrace(): void
    {
        $profile = new Profile();
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/path/a.php', 10),
        );
        $profile->addTrace($trace);

        $this->assertCount(1, $profile->functions);
        $this->assertArrayHasKey('func_a', $profile->functions);

        $entry = $profile->functions['func_a'];
        $this->assertSame('func_a', $entry->function_name);
        $this->assertSame('/path/a.php', $entry->file_name);
        $this->assertSame([10 => 1], $entry->lineno_samples);
        $this->assertCount(0, $entry->calls);
    }

    public function testAddMultiFrameTrace(): void
    {
        $profile = new Profile();
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('leaf', '/path/leaf.php', 5),
            new ParsedCallFrame('caller', '/path/caller.php', 15),
        );
        $profile->addTrace($trace);

        $this->assertCount(2, $profile->functions);

        // leaf frame has no callee, so it gets a line sample
        $leaf = $profile->functions['leaf'];
        $this->assertSame([5 => 1], $leaf->lineno_samples);
        $this->assertCount(0, $leaf->calls);

        // caller has leaf as callee, so it gets a call entry
        $caller = $profile->functions['caller'];
        $this->assertCount(0, $caller->lineno_samples);
        $this->assertCount(1, $caller->calls);

        $callEntry = array_values($caller->calls)[0];
        $this->assertSame($leaf, $callEntry->callee);
        $this->assertSame(15, $callEntry->caller_lineno);
        $this->assertSame(1, $callEntry->samples);
    }

    public function testAddMultipleTracesAggregatesSamples(): void
    {
        $profile = new Profile();

        $trace1 = new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/path/a.php', 10),
        );
        $trace2 = new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/path/a.php', 10),
        );
        $profile->addTrace($trace1);
        $profile->addTrace($trace2);

        $this->assertCount(1, $profile->functions);
        $this->assertSame([10 => 2], $profile->functions['func_a']->lineno_samples);
    }

    public function testAddMultipleTracesAggregatesCallEntries(): void
    {
        $profile = new Profile();

        for ($i = 0; $i < 3; $i++) {
            $profile->addTrace(new ParsedCallTrace(
                new ParsedCallFrame('leaf', '/path/leaf.php', 5),
                new ParsedCallFrame('caller', '/path/caller.php', 15),
            ));
        }

        $caller = $profile->functions['caller'];
        $callEntry = array_values($caller->calls)[0];
        $this->assertSame(3, $callEntry->samples);
    }

    public function testThreeDeepCallChain(): void
    {
        $profile = new Profile();
        $trace = new ParsedCallTrace(
            new ParsedCallFrame('bottom', '/b.php', 1),
            new ParsedCallFrame('middle', '/m.php', 2),
            new ParsedCallFrame('top', '/t.php', 3),
        );
        $profile->addTrace($trace);

        $this->assertCount(3, $profile->functions);

        // bottom: leaf, gets line sample
        $this->assertSame([1 => 1], $profile->functions['bottom']->lineno_samples);
        $this->assertCount(0, $profile->functions['bottom']->calls);

        // middle: calls bottom
        $this->assertCount(0, $profile->functions['middle']->lineno_samples);
        $this->assertCount(1, $profile->functions['middle']->calls);
        $middleCall = array_values($profile->functions['middle']->calls)[0];
        $this->assertSame($profile->functions['bottom'], $middleCall->callee);

        // top: calls middle
        $this->assertCount(0, $profile->functions['top']->lineno_samples);
        $this->assertCount(1, $profile->functions['top']->calls);
        $topCall = array_values($profile->functions['top']->calls)[0];
        $this->assertSame($profile->functions['middle'], $topCall->callee);
    }

    public function testFunctionEntryIsReusedAcrossTraces(): void
    {
        $profile = new Profile();

        $profile->addTrace(new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/a.php', 10),
        ));
        $profile->addTrace(new ParsedCallTrace(
            new ParsedCallFrame('func_a', '/a.php', 20),
        ));

        $this->assertCount(1, $profile->functions);
        $this->assertSame([10 => 1, 20 => 1], $profile->functions['func_a']->lineno_samples);
    }
}
