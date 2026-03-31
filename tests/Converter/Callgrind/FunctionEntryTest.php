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

class FunctionEntryTest extends BaseTestCase
{
    public function testAddSampleWithoutCallee(): void
    {
        $entry = new FunctionEntry('func_a', '/path/a.php');
        $entry->addSample(10, null);

        $this->assertSame([10 => 1], $entry->lineno_samples);
        $this->assertCount(0, $entry->calls);
    }

    public function testAddSampleWithoutCalleeAccumulates(): void
    {
        $entry = new FunctionEntry('func_a', '/path/a.php');
        $entry->addSample(10, null);
        $entry->addSample(10, null);
        $entry->addSample(20, null);

        $this->assertSame([10 => 2, 20 => 1], $entry->lineno_samples);
    }

    public function testAddSampleWithCallee(): void
    {
        $caller = new FunctionEntry('caller', '/caller.php');
        $callee = new FunctionEntry('callee', '/callee.php');

        $caller->addSample(15, $callee);

        $this->assertCount(0, $caller->lineno_samples);
        $this->assertCount(1, $caller->calls);

        $callEntry = array_values($caller->calls)[0];
        $this->assertSame($callee, $callEntry->callee);
        $this->assertSame(15, $callEntry->caller_lineno);
        $this->assertSame(1, $callEntry->samples);
    }

    public function testAddSampleWithCalleeAccumulates(): void
    {
        $caller = new FunctionEntry('caller', '/caller.php');
        $callee = new FunctionEntry('callee', '/callee.php');

        $caller->addSample(15, $callee);
        $caller->addSample(15, $callee);

        $this->assertCount(1, $caller->calls);
        $callEntry = array_values($caller->calls)[0];
        $this->assertSame(2, $callEntry->samples);
    }

    public function testCallEntryKeyDistinguishesByCalleeAndLine(): void
    {
        $caller = new FunctionEntry('caller', '/caller.php');
        $calleeA = new FunctionEntry('callee_a', '/a.php');
        $calleeB = new FunctionEntry('callee_b', '/b.php');

        $caller->addSample(10, $calleeA);
        $caller->addSample(10, $calleeB);
        $caller->addSample(20, $calleeA);

        $this->assertCount(3, $caller->calls);
    }
}
