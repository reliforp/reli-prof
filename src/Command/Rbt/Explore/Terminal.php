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

namespace Reli\Command\Rbt\Explore;

/**
 * Raw-mode terminal helper for the explorer TUI.
 *
 * Opens /dev/tty for both keyboard input and screen output, switches the
 * terminal into raw mode + alt screen on `enter()`, and *always* restores
 * the original state on shutdown — even on uncaught exceptions or signals.
 *
 * Read strategy: `stty min 1 time 1` returns at least one byte immediately
 * and any follow-up bytes within ~100ms. That captures multi-byte escape
 * sequences (arrow keys, PgUp/Dn, ...) in a single fread, while still
 * delivering single keystrokes without noticeable latency.
 *
 * stty is invoked with `< /dev/tty` so it always operates on the terminal
 * — important when PHP's own stdin has been redirected away from a tty
 * (Docker without -it, IDE task runners, piped input, ...). Without that
 * redirect, stty silently no-ops and the TUI appears to ignore every key.
 */
final class Terminal
{
    /** @var resource|null */
    private $tty_in = null;

    /** @var resource|null */
    private $tty_out = null;

    private ?string $stty_orig = null;

    private bool $entered = false;

    public function __construct()
    {
        register_shutdown_function([$this, 'leave']);
    }

    public function enter(): void
    {
        if ($this->entered) {
            return;
        }
        $in = @fopen('/dev/tty', 'rb');
        $out = @fopen('/dev/tty', 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException(
                'rbt:explore requires a terminal — /dev/tty could not be opened.'
            );
        }
        $this->tty_in = $in;
        $this->tty_out = $out;
        // Disable PHP's stream-level read buffering so single-keystroke
        // reads aren't held back waiting to fill an 8 KB buffer.
        @stream_set_read_buffer($this->tty_in, 0);
        @stream_set_write_buffer($this->tty_out, 0);

        $this->stty_orig = $this->captureStty();
        if ($this->stty_orig === null) {
            // Clean up the resources we opened so leave() doesn't try to
            // restore a state we never captured.
            fclose($this->tty_in);
            fclose($this->tty_out);
            $this->tty_in = null;
            $this->tty_out = null;
            throw new \RuntimeException(
                'rbt:explore could not configure the terminal: '
                . 'stty -g < /dev/tty failed. Run from an interactive '
                . 'terminal (not under nohup, a CI runner, or a shell whose '
                . 'stdin is redirected).'
            );
        }
        $this->applyStty('-icanon -echo min 1 time 1');

        // Alt screen on, hide cursor.
        $this->write("\e[?1049h\e[?25l");

        $this->entered = true;
    }

    public function leave(): void
    {
        if (!$this->entered) {
            return;
        }
        $this->entered = false;

        // Show cursor, leave alt screen.
        $this->write("\e[?25h\e[?1049l");

        if ($this->stty_orig !== null) {
            $this->applyStty($this->stty_orig);
        } else {
            $this->applyStty('sane');
        }

        if (is_resource($this->tty_in)) {
            fclose($this->tty_in);
        }
        if (is_resource($this->tty_out)) {
            fclose($this->tty_out);
        }
        $this->tty_in = null;
        $this->tty_out = null;
    }

    /**
     * Read one logical key (single byte or full escape sequence).
     *
     * In raw mode with VMIN=1, VTIME=1, fread blocks until at least one
     * byte arrives, then waits ~100ms for any follow-up bytes — long
     * enough to deliver a multi-byte escape sequence (`\e[A`, ...) in
     * one read, short enough to feel instant for single keystrokes.
     *
     * Returns an empty string only when the underlying read fails or
     * the resource is closed; the caller may treat that as "try again".
     */
    public function readKey(): string
    {
        if ($this->tty_in === null) {
            throw new \RuntimeException('Terminal not entered.');
        }
        $bytes = @fread($this->tty_in, 64);
        return $bytes === false ? '' : $bytes;
    }

    /**
     * Block until a key is available and return it. Provided as a
     * separate entry point so future versions can interleave SIGWINCH
     * handling or polling without changing the call site.
     */
    public function pollKey(): string
    {
        return $this->readKey();
    }

    public function write(string $s): void
    {
        if ($this->tty_out === null) {
            return;
        }
        fwrite($this->tty_out, $s);
        fflush($this->tty_out);
    }

    public function clear(): void
    {
        $this->write("\e[2J\e[H");
    }

    public function moveCursor(int $row, int $col): void
    {
        $this->write("\e[{$row};{$col}H");
    }

    /**
     * @return array{int, int} [columns, rows]
     */
    public function size(): array
    {
        $cols = (int)(getenv('COLUMNS') ?: 0);
        $rows = (int)(getenv('LINES') ?: 0);
        if ($cols <= 0 || $rows <= 0) {
            $stty = @shell_exec('stty size 2>/dev/null') ?: '';
            $parts = preg_split('/\s+/', trim($stty));
            if ($parts !== false && count($parts) >= 2) {
                $rows = (int)$parts[0];
                $cols = (int)$parts[1];
            }
        }
        return [$cols > 0 ? $cols : 80, $rows > 0 ? $rows : 24];
    }

    private function captureStty(): ?string
    {
        $out = @shell_exec('stty -g < /dev/tty 2>/dev/null');
        if ($out === null || $out === false) {
            return null;
        }
        $trimmed = trim($out);
        return $trimmed === '' ? null : $trimmed;
    }

    private function applyStty(string $args): void
    {
        @shell_exec('stty ' . $args . ' < /dev/tty 2>/dev/null');
    }
}
