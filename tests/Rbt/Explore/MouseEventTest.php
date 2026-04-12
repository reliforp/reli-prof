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

class MouseEventTest extends BaseTestCase
{
    public function testParseLeftPress(): void
    {
        $event = MouseEvent::tryParse("\e[<0;10;5M");
        $this->assertNotNull($event);
        $this->assertSame(MouseEvent::BUTTON_LEFT, $event->button);
        $this->assertSame(10, $event->col);
        $this->assertSame(5, $event->row);
        $this->assertTrue($event->press);
        $this->assertFalse($event->drag);
    }

    public function testParseLeftRelease(): void
    {
        $event = MouseEvent::tryParse("\e[<0;10;5m");
        $this->assertNotNull($event);
        $this->assertSame(MouseEvent::BUTTON_LEFT, $event->button);
        $this->assertFalse($event->press);
    }

    public function testParseScrollUp(): void
    {
        $event = MouseEvent::tryParse("\e[<64;1;1M");
        $this->assertNotNull($event);
        $this->assertSame(MouseEvent::SCROLL_UP, $event->button);
        $this->assertTrue($event->press);
    }

    public function testParseScrollDown(): void
    {
        $event = MouseEvent::tryParse("\e[<65;1;1M");
        $this->assertNotNull($event);
        $this->assertSame(MouseEvent::SCROLL_DOWN, $event->button);
    }

    public function testParseDrag(): void
    {
        // button 0 + 32 (drag flag) = 32
        $event = MouseEvent::tryParse("\e[<32;20;10M");
        $this->assertNotNull($event);
        $this->assertSame(MouseEvent::BUTTON_LEFT, $event->button);
        $this->assertTrue($event->drag);
    }

    public function testParseLargeCoordinates(): void
    {
        $event = MouseEvent::tryParse("\e[<0;200;150M");
        $this->assertNotNull($event);
        $this->assertSame(200, $event->col);
        $this->assertSame(150, $event->row);
    }

    public function testParseMiddleButton(): void
    {
        $event = MouseEvent::tryParse("\e[<1;5;5M");
        $this->assertNotNull($event);
        $this->assertSame(MouseEvent::BUTTON_MIDDLE, $event->button);
    }

    public function testParseRightButton(): void
    {
        $event = MouseEvent::tryParse("\e[<2;5;5M");
        $this->assertNotNull($event);
        $this->assertSame(MouseEvent::BUTTON_RIGHT, $event->button);
    }

    public function testReturnsNullForNonMouseSequence(): void
    {
        $this->assertNull(MouseEvent::tryParse("\e[A"));
        $this->assertNull(MouseEvent::tryParse("a"));
        $this->assertNull(MouseEvent::tryParse("\e[5~"));
    }

    public function testParseShiftModifier(): void
    {
        // Shift adds bit 2 (4) to Cb. Left click + shift = 0+4 = 4.
        $event = MouseEvent::tryParse("\e[<4;10;5M");
        $this->assertNotNull($event);
        $this->assertSame(MouseEvent::BUTTON_LEFT, $event->button);
        $this->assertTrue($event->shift);
        $this->assertFalse($event->drag);
    }

    public function testShiftScrollUp(): void
    {
        // Scroll up (64) + shift (4) = 68.
        $event = MouseEvent::tryParse("\e[<68;1;1M");
        $this->assertNotNull($event);
        $this->assertSame(MouseEvent::SCROLL_UP, $event->button);
        $this->assertTrue($event->shift);
    }

    public function testNoShiftByDefault(): void
    {
        $event = MouseEvent::tryParse("\e[<0;10;5M");
        $this->assertNotNull($event);
        $this->assertFalse($event->shift);
    }

    public function testReturnsNullForMalformed(): void
    {
        $this->assertNull(MouseEvent::tryParse("\e[<0;10M"));
        $this->assertNull(MouseEvent::tryParse("\e[<;10;5M"));
    }
}
