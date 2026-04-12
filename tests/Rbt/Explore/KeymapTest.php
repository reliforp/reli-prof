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

class KeymapTest extends BaseTestCase
{
    public function testDefaultBindingsCoverArrowsAndVim(): void
    {
        $km = Keymap::default();
        $this->assertSame(Keymap::ACTION_UP, $km->resolve("\e[A"));
        $this->assertSame(Keymap::ACTION_UP, $km->resolve('k'));
        $this->assertSame(Keymap::ACTION_DOWN, $km->resolve("\e[B"));
        $this->assertSame(Keymap::ACTION_DOWN, $km->resolve('j'));
        $this->assertSame(Keymap::ACTION_CALLERS, $km->resolve("\e[D"));
        $this->assertSame(Keymap::ACTION_CALLEES, $km->resolve("\e[C"));
        $this->assertSame(Keymap::ACTION_FOCUS_ENTER, $km->resolve("\r"));
        $this->assertSame(Keymap::ACTION_BACK, $km->resolve('u'));
        $this->assertSame(Keymap::ACTION_QUIT, $km->resolve('q'));
        $this->assertSame(Keymap::ACTION_QUIT, $km->resolve("\x03"));
    }

    public function testUnknownSequenceResolvesToNull(): void
    {
        $km = Keymap::default();
        $this->assertNull($km->resolve('Z'));
        $this->assertNull($km->resolve("\e[X"));
    }

    public function testShiftTabResolvesToReversePaneCycle(): void
    {
        $km = Keymap::default();
        $this->assertSame(Keymap::ACTION_TOGGLE_PANE_REVERSE, $km->resolve("\e[Z"));
    }

    public function testFromJsonFileOverridesAndKeepsDefaults(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'reli_km_');
        $this->assertNotFalse($tmp);
        try {
            file_put_contents($tmp, json_encode([
                Keymap::ACTION_QUIT => ['Q'],
                Keymap::ACTION_HELP => ['?', "\u{0008}"],
            ]));

            $km = Keymap::fromJsonFile($tmp);
            // Overridden binding takes effect.
            $this->assertSame(Keymap::ACTION_QUIT, $km->resolve('Q'));
            // Original default for the same action is replaced.
            $this->assertNull($km->resolve('q'));
            // Untouched actions still have their default bindings.
            $this->assertSame(Keymap::ACTION_UP, $km->resolve("\e[A"));
            $this->assertSame(Keymap::ACTION_DOWN, $km->resolve('j'));
            // Multi-binding override.
            $this->assertSame(Keymap::ACTION_HELP, $km->resolve('?'));
            $this->assertSame(Keymap::ACTION_HELP, $km->resolve("\x08"));
        } finally {
            @unlink($tmp);
        }
    }

    public function testFromJsonFileMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Keymap::fromJsonFile('/nonexistent/keymap.json');
    }

    public function testFromJsonFileMalformedThrows(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'reli_km_');
        $this->assertNotFalse($tmp);
        try {
            file_put_contents($tmp, 'not json');
            $this->expectException(\RuntimeException::class);
            Keymap::fromJsonFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testFromJsonFileSilentlySkipsBogusEntries(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'reli_km_');
        $this->assertNotFalse($tmp);
        try {
            file_put_contents($tmp, json_encode([
                Keymap::ACTION_VIEW_SELF => 'not-a-list',
                Keymap::ACTION_VIEW_TOTAL => [123, 'T'], // 123 is filtered, 'T' kept
                Keymap::ACTION_NO_LINE => [],            // empty list ignored
            ]));

            $km = Keymap::fromJsonFile($tmp);
            $this->assertSame(Keymap::ACTION_VIEW_SELF, $km->resolve('s')); // default kept
            $this->assertSame(Keymap::ACTION_VIEW_TOTAL, $km->resolve('T'));
            // Old default 't' must be gone since the action's bindings list was replaced.
            $this->assertNull($km->resolve('t'));
            $this->assertSame(Keymap::ACTION_NO_LINE, $km->resolve('n')); // default kept
        } finally {
            @unlink($tmp);
        }
    }
}
