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

class PhpSpyCompatibleDataContextTest extends BaseTestCase
{
    public function testToString(): void
    {
        $context = new PhpSpyCompatibleDataContext(42);
        $this->assertSame('line:42', $context->toString());
    }

    public function testLineno(): void
    {
        $context = new PhpSpyCompatibleDataContext(123);
        $this->assertSame(123, $context->lineno);
    }
}
