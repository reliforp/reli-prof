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

class CallEntryTest extends BaseTestCase
{
    public function testConstruct(): void
    {
        $callee = new FunctionEntry('callee', '/callee.php');
        $entry = new CallEntry($callee, 42);

        $this->assertSame($callee, $entry->callee);
        $this->assertSame(42, $entry->caller_lineno);
        $this->assertSame(0, $entry->samples);
    }
}
