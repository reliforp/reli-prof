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
 * {@see TraceAggregator}: frame-level path shortening, and line-level
 * width cropping with a trailing ellipsis.
 *
 * Both transforms are purely cosmetic — aggregation keys are untouched,
 * so two distinct call sites that collapse to the same basename still
 * retain separate counts in the self/total tables.
 *
 * The two live together because they're the two cooperating levers the
 * CLI exposes to tame wide traces: `--path=short` shrinks the natural
 * column width; `--crop` hard-caps whatever's left so very long
 * FQN-only frames don't line-wrap the layout.
 */
final class FrameFormatter
{
    public const PATH_FULL = 'full';
    public const PATH_SHORT = 'short';

    /**
     * `path_mode`:
     *   - PATH_FULL  → leave frame strings as-is.
     *   - PATH_SHORT → replace the directory portion of `file:line`
     *                  with the basename (`Worker.php:42`).
     */
    public function __construct(
        public readonly string $path_mode = self::PATH_FULL,
    ) {
    }

    /**
     * Frame-level transform: applies path shortening only.
     *
     * Use this on the value that will end up in the "frame" column of
     * a table, or the body of a tail line — i.e. on content that's
     * still going to be laid out / padded downstream.
     */
    public function formatFrame(string $frame): string
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

    /**
     * Line-level transform: hard-cap a fully rendered output line
     * (including any table borders) at `$width` characters, replacing
     * the last visible character with an ellipsis when clipping.
     *
     * `$width <= 0` is a no-op so callers can unconditionally route
     * lines through this when crop is disabled.
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
}
