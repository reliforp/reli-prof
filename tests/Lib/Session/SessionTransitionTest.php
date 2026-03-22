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

#[CoversClass(SessionTransition::class)]
final class SessionTransitionTest extends BaseTestCase
{
    public function testConstruction(): void
    {
        $transition = new SessionTransition(
            direction: Direction::Send,
            messageClass: \stdClass::class,
            nextState: 'NextState',
        );

        $this->assertSame(Direction::Send, $transition->direction);
        $this->assertSame(\stdClass::class, $transition->messageClass);
        $this->assertSame('NextState', $transition->nextState);
    }

    public function testReadonlyProperties(): void
    {
        $transition = new SessionTransition(
            direction: Direction::Recv,
            messageClass: \stdClass::class,
            nextState: 'SomeState',
        );

        $ref = new \ReflectionClass($transition);
        foreach ($ref->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly());
        }
    }
}
