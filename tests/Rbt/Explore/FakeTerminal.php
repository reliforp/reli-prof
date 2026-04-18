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
 * Test double for {@see TerminalInterface}.
 *
 * Captures every byte ExploreTui writes into an internal buffer so
 * tests can assert on the rendered frames, and serves keystrokes from
 * a scripted FIFO queue so tests can drive the dispatch loop without
 * a real tty. Reports a fixed cols×rows so the renderer's geometry is
 * deterministic.
 *
 * Lives under tests/ rather than src/ on purpose: it's not part of the
 * shipped surface, just a fixture for the explore TUI tests.
 */
final class FakeTerminal implements TerminalInterface
{
    private string $buffer = '';
    private bool $entered = false;

    /** @var list<string> scripted keystrokes, oldest first */
    private array $keys = [];

    public function __construct(
        private int $cols = 120,
        private int $rows = 30,
    ) {
    }

    /**
     * Append scripted keystrokes to be returned by subsequent
     * readKey() / pollKey() calls. Each item is one logical key —
     * no escape sequence drain happens, so multi-byte sequences
     * (e.g. arrow keys) should be supplied pre-assembled.
     */
    public function script(string ...$keys): void
    {
        foreach ($keys as $k) {
            $this->keys[] = $k;
        }
    }

    /**
     * The full byte stream ExploreTui has written so far. Useful for
     * smoke assertions like "the flame view body contains a Braille
     * cell" without making the test sensitive to layout pixel widths.
     */
    public function output(): string
    {
        return $this->buffer;
    }

    /**
     * The output with ANSI CSI / OSC escapes stripped. Most render
     * assertions want to grep for human-readable text without the
     * surrounding `\e[…m` color noise; this is the easy path.
     */
    public function plainOutput(): string
    {
        // CSI sequences (ESC [ … final-byte). Covers SGR (color/bold),
        // cursor moves, screen clears, and the alt-screen toggles.
        return preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $this->buffer) ?? $this->buffer;
    }

    public function clearBuffer(): void
    {
        $this->buffer = '';
    }

    public function isEntered(): bool
    {
        return $this->entered;
    }

    #[\Override]
    public function enter(): void
    {
        $this->entered = true;
    }

    #[\Override]
    public function leave(): void
    {
        $this->entered = false;
    }

    #[\Override]
    public function readKey(): string
    {
        if ($this->keys === []) {
            return '';
        }
        return array_shift($this->keys);
    }

    #[\Override]
    public function pollKey(): string
    {
        return $this->readKey();
    }

    #[\Override]
    public function pollKeyTimeout(int $timeoutMs): ?string
    {
        if ($this->keys === []) {
            return null;
        }
        return $this->readKey();
    }

    #[\Override]
    public function write(string $s): void
    {
        $this->buffer .= $s;
    }

    #[\Override]
    public function clear(): void
    {
        // Mirror Terminal::clear() — write the same escape so render
        // assertions that don't strip ANSI still see a sane stream.
        $this->write("\e[2J\e[H");
    }

    /**
     * @return array{int, int}
     */
    #[\Override]
    public function size(): array
    {
        return [$this->cols, $this->rows];
    }
}
