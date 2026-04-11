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
 * stty is invoked with `< /dev/tty` so it always operates on the terminal,
 * not on whatever happens to be PHP's current stdin (Docker without -it,
 * IDE task runners, redirected stdin, ...).
 *
 * Read strategy: PHP `fread(stream, N)` ultimately calls libc `fread()`,
 * which loops calling `read()` until it has the requested N bytes. On a
 * raw-mode tty that means `fread(64)` blocks waiting for the user to
 * press 64 keys before it returns anything. To get one keystroke per
 * call we have to (a) disable PHP's stream-level chunk buffering with
 * `stream_set_read_buffer(0)` so php_stream_read passes our exact
 * requested size through to libc, and (b) read one byte at a time with
 * `fgetc`, which drives libc fread with size 1 — a single `read()`
 * syscall returning the available byte. Multi-byte escape sequences
 * (arrow keys etc) are then drained with the stream switched to
 * non-blocking so the loop stops as soon as the kernel buffer is empty.
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
            // restore a state we never captured. Detach from $this BEFORE
            // fclose so the property type stays `resource|null` (psalm
            // would otherwise narrow it to `closed-resource` after
            // fclose, which conflicts with the declared property type).
            $tty_in = $this->tty_in;
            $tty_out = $this->tty_out;
            $this->tty_in = null;
            $this->tty_out = null;
            fclose($tty_in);
            fclose($tty_out);
            throw new \RuntimeException(
                'rbt:explore could not configure the terminal: '
                . 'stty -g < /dev/tty failed. Run from an interactive '
                . 'terminal (not under nohup, a CI runner, or a shell whose '
                . 'stdin is redirected).'
            );
        }
        // min 1 time 0: block until at least one byte is available with no
        // inter-byte timer. We compose multi-byte sequences ourselves via
        // a non-blocking drain so we don't pay the 100 ms inter-byte delay
        // that `time 1` would add to every single keystroke.
        $this->applyStty('-icanon -echo min 1 time 0');

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

        // Detach BEFORE fclose so psalm doesn't narrow the property
        // to closed-resource (see enter()).
        $tty_in = $this->tty_in;
        $tty_out = $this->tty_out;
        $this->tty_in = null;
        $this->tty_out = null;
        if (is_resource($tty_in)) {
            fclose($tty_in);
        }
        if (is_resource($tty_out)) {
            fclose($tty_out);
        }
    }

    /**
     * Read one logical key (single byte or full escape sequence).
     *
     * fgetc reads exactly one byte at a time, which drives libc fread
     * with size 1 — a single read() syscall returning the available
     * byte (kernel honours VMIN=1). After an ESC byte we switch the
     * stream to non-blocking and drain whatever else the kernel has
     * already queued so multi-byte sequences (arrow keys etc) come
     * back as one logical token.
     *
     * Returns an empty string only when the underlying read fails or
     * the resource is closed; the caller may treat that as "try again".
     */
    public function readKey(): string
    {
        if ($this->tty_in === null) {
            throw new \RuntimeException('Terminal not entered.');
        }

        $first = @fgetc($this->tty_in);
        if ($first === false || $first === '') {
            return '';
        }

        if ($first !== "\e") {
            return $first;
        }

        // Drain the rest of the escape sequence without blocking on the
        // next keystroke. Most terminals deliver "\e[A" / "\e[5~" / etc
        // as a single tty write so the trailing bytes are usually already
        // in libc's buffer by the time we get here; if they aren't, we
        // simply return the bytes we have and let the keymap try to
        // resolve a partial sequence (which it will refuse and ignore).
        @stream_set_blocking($this->tty_in, false);
        $bytes = $first;
        for ($i = 0; $i < 8; $i++) {
            $next = @fgetc($this->tty_in);
            if ($next === false || $next === '') {
                break;
            }
            $bytes .= $next;
        }
        @stream_set_blocking($this->tty_in, true);
        return $bytes;
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
        $cols_env = getenv('COLUMNS');
        $rows_env = getenv('LINES');
        $cols = is_string($cols_env) && $cols_env !== '' ? (int)$cols_env : 0;
        $rows = is_string($rows_env) && $rows_env !== '' ? (int)$rows_env : 0;
        if ($cols <= 0 || $rows <= 0) {
            /** @psalm-suppress ForbiddenCode */
            $stty = @shell_exec('stty size 2>/dev/null');
            if (is_string($stty)) {
                $parts = preg_split('/\s+/', trim($stty));
                if ($parts !== false && count($parts) >= 2) {
                    $rows = (int)$parts[0];
                    $cols = (int)$parts[1];
                }
            }
        }
        return [$cols > 0 ? $cols : 80, $rows > 0 ? $rows : 24];
    }

    private function captureStty(): ?string
    {
        /** @psalm-suppress ForbiddenCode */
        $out = @shell_exec('stty -g < /dev/tty 2>/dev/null');
        if ($out === null || $out === false) {
            return null;
        }
        $trimmed = trim($out);
        return $trimmed === '' ? null : $trimmed;
    }

    private function applyStty(string $args): void
    {
        /** @psalm-suppress ForbiddenCode */
        @shell_exec('stty ' . $args . ' < /dev/tty 2>/dev/null');
    }
}
