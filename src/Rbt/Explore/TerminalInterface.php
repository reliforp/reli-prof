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
 * Minimal terminal surface the explorer TUI needs to drive its
 * render / input loop. The {@see Terminal} concrete implementation is
 * the production /dev/tty + raw-mode driver; tests substitute a fake
 * that captures writes and feeds scripted keystrokes.
 *
 * Methods are deliberately kept to the small set ExploreTui actually
 * uses — pulling more of Terminal's internals into the contract would
 * tie the interface to one particular tty plumbing approach. Anything
 * the concrete implementation needs but the interface doesn't expose
 * (e.g. moveCursor) stays a Terminal-only concern.
 */
interface TerminalInterface
{
    /**
     * Switch the terminal into raw / alt-screen mode. Implementations
     * are expected to be idempotent — calling enter() twice is a no-op,
     * not an error.
     *
     * @throws \RuntimeException if the terminal cannot be configured
     *                           (no /dev/tty, stty failure, etc.)
     */
    public function enter(): void;

    /**
     * Restore the original terminal state. Idempotent so it's safe to
     * register as a shutdown handler / call from both `finally` and
     * the destructor without double-restoring.
     */
    public function leave(): void;

    /**
     * Block until a single byte is available, then drain any trailing
     * bytes that belong to the same escape sequence (so multi-byte
     * arrow / function keys come back as one logical token). Returns
     * an empty string only if the underlying read failed or the
     * stream was closed.
     */
    public function readKey(): string;

    /**
     * Block until a key is available and return it. Provided as a
     * separate entry point so future versions can interleave SIGWINCH
     * handling or polling without changing call sites; the default
     * implementation just delegates to readKey().
     */
    public function pollKey(): string;

    public function write(string $s): void;

    public function clear(): void;

    /**
     * Current terminal dimensions. Implementations should fall back
     * to a reasonable default (e.g. 80x24) when the actual size can't
     * be queried so the renderer never has to special-case zero.
     *
     * @return array{int, int} [columns, rows]
     */
    public function size(): array;
}
