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

namespace Reli\Rbt\Analyze;

/**
 * Display-time transforms for frame strings produced by
 * {@see TraceAggregator}: frame-level path shortening and
 * anchor-directional width cropping.
 *
 * Both transforms are purely cosmetic — aggregation keys are untouched,
 * so two distinct call sites that collapse to the same basename still
 * retain separate counts in the self/total tables.
 *
 * Cropping anchor:
 *   - ANCHOR_LEFT  → keep the head of the string, trailing ellipsis:
 *                    `Reli\Inspector\MemoryDump\FastPath\Re…`
 *                    — good when the distinguishing prefix is what you care about.
 *   - ANCHOR_RIGHT → keep the tail of the string, leading ellipsis:
 *                    `…\FastPath\RegionByteProvider::region`
 *                    — good when you care about the leaf function and
 *                    deep namespace prefixes are share-able noise.
 *
 * Frame cropping happens *before* a Table row is built, not after the
 * line is rendered, so Symfony's Table auto-sizes its borders around
 * the already-cropped content. That keeps the right-hand `|` border
 * intact instead of getting chewed off by a naive line-level crop.
 */
final class FrameFormatter
{
    public const PATH_FULL = 'full';
    public const PATH_SHORT = 'short';

    public const ANCHOR_LEFT = 'left';
    public const ANCHOR_RIGHT = 'right';

    public function __construct(
        public readonly string $path_mode = self::PATH_FULL,
        public readonly string $anchor = self::ANCHOR_LEFT,
    ) {
    }

    /**
     * Path-shorten the frame but leave its length alone. Use this when
     * the caller has no width budget (no `--crop`, or the section it's
     * going into lays out at natural width).
     */
    public function formatFrame(string $frame): string
    {
        return $this->shortenPath($frame);
    }

    /**
     * Path-shorten, then crop to `$budget` characters (anchor-aware).
     *
     * `$budget <= 0` disables cropping (returns the path-shortened
     * frame as-is). A budget of 1 collapses to a lone `…`, which is
     * a degenerate corner we guard against explicitly.
     */
    public function cropFrame(string $frame, int $budget): string
    {
        $frame = $this->shortenPath($frame);
        if ($budget <= 0) {
            return $frame;
        }
        $len = mb_strlen($frame);
        if ($len <= $budget) {
            return $frame;
        }
        if ($budget === 1) {
            return '…';
        }
        if ($this->anchor === self::ANCHOR_RIGHT) {
            return '…' . mb_substr($frame, $len - ($budget - 1));
        }
        return mb_substr($frame, 0, $budget - 1) . '…';
    }

    /**
     * Line-level hard cap: crop a fully rendered output line at
     * `$width` chars with a trailing ellipsis. Anchor-agnostic — used
     * for section titles and other non-table lines that don't have
     * borders to protect.
     */
    public static function cropLine(string $line, int $width): string
    {
        if ($width <= 0) {
            return $line;
        }
        $len = mb_strlen($line);
        if ($len <= $width) {
            return $line;
        }
        if ($width === 1) {
            return '…';
        }
        return mb_substr($line, 0, $width - 1) . '…';
    }

    private function shortenPath(string $frame): string
    {
        if ($this->path_mode !== self::PATH_SHORT) {
            return $frame;
        }

        // Pull off a trailing " [OPCODE]" suffix (added by --with-opcode)
        // so the path regex below only has to worry about "... file:line".
        $opcode_suffix = '';
        $body = $frame;
        if (preg_match('/ \[[^\]]+\]$/', $body, $m) === 1) {
            $opcode_suffix = $m[0];
            $body = substr($body, 0, -strlen($m[0]));
        }

        // function_name never contains spaces in PHP traces, and lineno
        // is an integer, so "<everything> <path>:<lineno>" splits
        // unambiguously on the last space. Pseudo-paths like "<internal>"
        // stay unchanged because basename leaves them alone.
        if (preg_match('/^(.*) ([^ ]+):(-?\d+)$/', $body, $m) !== 1) {
            return $frame;
        }

        $basename = basename($m[2]);
        if ($basename === '') {
            return $frame;
        }
        return $m[1] . ' ' . $basename . ':' . $m[3] . $opcode_suffix;
    }
}
