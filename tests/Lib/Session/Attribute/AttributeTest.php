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

namespace Reli\Lib\Session\Attribute;

use Attribute;
use PHPUnit\Framework\Attributes\CoversClass;
use Reli\BaseTestCase;
use Reli\Inspector\Daemon\Reader\Protocol\PhpReaderSession;

#[CoversClass(Initial::class)]
#[CoversClass(Recv::class)]
#[CoversClass(Send::class)]
final class AttributeTest extends BaseTestCase
{
    public function testInitialIsClassConstantAttribute(): void
    {
        $ref = new \ReflectionClass(Initial::class);
        $attrs = $ref->getAttributes(Attribute::class);
        $this->assertCount(1, $attrs);
        $instance = $attrs[0]->newInstance();
        $this->assertSame(Attribute::TARGET_CLASS_CONSTANT, $instance->flags);
    }

    public function testRecvProperties(): void
    {
        $recv = new Recv(
            messageClass: \stdClass::class,
            next: PhpReaderSession::AwaitingAttach,
        );

        $this->assertSame(\stdClass::class, $recv->messageClass);
        $this->assertSame(PhpReaderSession::AwaitingAttach, $recv->next);
    }

    public function testRecvIsRepeatableClassConstantAttribute(): void
    {
        $ref = new \ReflectionClass(Recv::class);
        $attrs = $ref->getAttributes(Attribute::class);
        $this->assertCount(1, $attrs);
        $instance = $attrs[0]->newInstance();
        $this->assertSame(
            Attribute::TARGET_CLASS_CONSTANT | Attribute::IS_REPEATABLE,
            $instance->flags,
        );
    }

    public function testSendProperties(): void
    {
        $send = new Send(
            messageClass: \stdClass::class,
            next: PhpReaderSession::Tracing,
        );

        $this->assertSame(\stdClass::class, $send->messageClass);
        $this->assertSame(PhpReaderSession::Tracing, $send->next);
    }

    public function testSendIsRepeatableClassConstantAttribute(): void
    {
        $ref = new \ReflectionClass(Send::class);
        $attrs = $ref->getAttributes(Attribute::class);
        $this->assertCount(1, $attrs);
        $instance = $attrs[0]->newInstance();
        $this->assertSame(
            Attribute::TARGET_CLASS_CONSTANT | Attribute::IS_REPEATABLE,
            $instance->flags,
        );
    }
}
