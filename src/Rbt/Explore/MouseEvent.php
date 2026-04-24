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

/**
 * Parsed SGR mouse event (mode 1006).
 *
 * SGR format: \e[<Cb;Cx;CyM  (press) or \e[<Cb;Cx;Cym (release)
 * where Cb = button flags, Cx = 1-based column, Cy = 1-based row.
 *
 * With mode 1003 (any-motion tracking) enabled on top, mouse motion
 * without any button held also arrives here — recognisable by
 * `drag=true` together with `button=BUTTON_NONE`.
 */
final class MouseEvent
{
    public const BUTTON_LEFT = 0;
    public const BUTTON_MIDDLE = 1;
    public const BUTTON_RIGHT = 2;
    public const BUTTON_NONE = 3;
    public const SCROLL_UP = 64;
    public const SCROLL_DOWN = 65;

    public function __construct(
        public readonly int $button,
        public readonly int $col,
        public readonly int $row,
        public readonly bool $press,
        public readonly bool $drag,
        public readonly bool $shift = false,
        public readonly bool $ctrl = false,
    ) {
    }

    /**
     * Convenience: the event is "hover" — motion without any button
     * held down — if the motion bit is set and the button field is
     * BUTTON_NONE. With mode 1002 only (drag-tracking) we never see
     * these; mode 1003 (any-motion) emits them on every pixel move.
     */
    public function isHover(): bool
    {
        return $this->drag && $this->button === self::BUTTON_NONE;
    }

    /**
     * Try to parse a raw escape sequence as an SGR mouse event.
     *
     * Returns null if the sequence is not a valid SGR mouse event.
     */
    public static function tryParse(string $seq): ?self
    {
        // SGR: \e[<Cb;Cx;CyM or \e[<Cb;Cx;Cym
        if (!preg_match('/^\e\[<(\d+);(\d+);(\d+)([Mm])$/', $seq, $m)) {
            return null;
        }
        $cb = (int)$m[1];
        $col = (int)$m[2];
        $row = (int)$m[3];
        $press = $m[4] === 'M';

        $shift = ($cb & 4) !== 0;
        $ctrl = ($cb & 16) !== 0;
        $drag = ($cb & 32) !== 0;
        $button = $cb & ~(4 | 8 | 16 | 32);

        return new self($button, $col, $row, $press, $drag, $shift, $ctrl);
    }
}
