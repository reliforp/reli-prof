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

namespace Reli\Rbt\Explore;

use Reli\BaseTestCase;

class ViewOptionsTest extends BaseTestCase
{
    public function testDefaultsAreNoLineOffAndMatchNull(): void
    {
        $opts = new ViewOptions();
        $this->assertFalse($opts->no_line);
        $this->assertNull($opts->match_re);
    }

    public function testWithNoLineFlipsTheFlagAndPreservesMatch(): void
    {
        $opts = new ViewOptions(no_line: false, match_re: '#^foo#');
        $next = $opts->withNoLine(true);

        $this->assertNotSame($opts, $next);
        $this->assertTrue($next->no_line);
        $this->assertSame('#^foo#', $next->match_re);

        // Round-trip back to false.
        $back = $next->withNoLine(false);
        $this->assertFalse($back->no_line);
        $this->assertSame('#^foo#', $back->match_re);
    }

    public function testWithMatchReplacesPatternAndPreservesNoLine(): void
    {
        $opts = new ViewOptions(no_line: true, match_re: null);
        $next = $opts->withMatch('#bar#');

        $this->assertNotSame($opts, $next);
        $this->assertTrue($next->no_line);
        $this->assertSame('#bar#', $next->match_re);
    }

    public function testWithMatchAcceptsNullToClearTheFilter(): void
    {
        $opts = new ViewOptions(no_line: false, match_re: '#x#');
        $cleared = $opts->withMatch(null);

        $this->assertNull($cleared->match_re);
        $this->assertFalse($cleared->no_line);
    }
}
