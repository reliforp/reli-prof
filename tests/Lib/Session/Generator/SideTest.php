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

namespace Reli\Lib\Session\Generator;

use PHPUnit\Framework\Attributes\CoversClass;
use Reli\BaseTestCase;

#[CoversClass(Side::class)]
final class SideTest extends BaseTestCase
{
    public function testWorkerLabel(): void
    {
        $this->assertSame('Worker', Side::Worker->label());
    }

    public function testControllerLabel(): void
    {
        $this->assertSame('Controller', Side::Controller->label());
    }
}
