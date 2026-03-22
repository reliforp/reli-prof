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

namespace Reli\Lib\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use Reli\BaseTestCase;

#[CoversClass(Direction::class)]
final class DirectionTest extends BaseTestCase
{
    public function testDualOfSendIsRecv(): void
    {
        $this->assertSame(Direction::Recv, Direction::Send->dual());
    }

    public function testDualOfRecvIsSend(): void
    {
        $this->assertSame(Direction::Send, Direction::Recv->dual());
    }

    public function testDualIsInvolution(): void
    {
        foreach (Direction::cases() as $direction) {
            $this->assertSame($direction, $direction->dual()->dual());
        }
    }
}
