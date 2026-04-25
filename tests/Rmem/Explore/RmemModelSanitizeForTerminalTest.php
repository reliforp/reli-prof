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

namespace Reli\Rmem\Explore;

use PHPUnit\Framework\TestCase;

class RmemModelSanitizeForTerminalTest extends TestCase
{
    public function testReplacesWhitespaceEscapesWithVisibleStandIns(): void
    {
        $this->assertSame(
            'hello\nworld\tend',
            RmemModel::sanitizeForTerminal("hello\nworld\tend"),
        );
    }

    public function testStripsAnsiEscapeSequences(): void
    {
        $this->assertSame(
            'plain text',
            RmemModel::sanitizeForTerminal("\e[31mplain \e[1;32mtext\e[0m"),
        );
    }

    public function testReplacesRemainingControlCharactersWithMiddot(): void
    {
        // BEL (0x07) + DEL (0x7f) should both become U+00B7 (·) which is
        // 0xC2 0xB7 in UTF-8.
        $out = RmemModel::sanitizeForTerminal("a\x07b\x7fc");
        $this->assertSame("a\xC2\xB7b\xC2\xB7c", $out);
    }

    public function testMaxLenCapsInputBeforeRegexScans(): void
    {
        // Build a 2 MB string with a few escapes sprinkled in; without the
        // cap, preg_replace would have to scan the whole thing.
        $payload = str_repeat("\x1b[31mabc\x1b[0m ", 100_000);
        $this->assertGreaterThan(1_000_000, strlen($payload));

        $out = RmemModel::sanitizeForTerminal($payload, 80);
        // Result is bounded (bytes). Exact length depends on where the
        // 80-byte prefix landed, but it can never exceed the cap + UTF-8
        // expansion of 0xC2 0xB7 replacements (which can't occur in an
        // ASCII-only prefix); so 80 bytes is a hard ceiling here.
        $this->assertLessThanOrEqual(80, strlen($out));
    }

    public function testMaxLenNullPreservesHistoricalUnboundedBehavior(): void
    {
        $payload = str_repeat('abc', 1000);
        $out = RmemModel::sanitizeForTerminal($payload, null);
        $this->assertSame($payload, $out);
    }

    public function testMaxLenLargerThanInputIsNoOp(): void
    {
        $payload = 'short string';
        $this->assertSame(
            $payload,
            RmemModel::sanitizeForTerminal($payload, 1_000),
        );
    }

    public function testMaxLenDoesNotBreakControlReplacementOnTruncatedTail(): void
    {
        // Cap falls mid-string; the surviving prefix should still get its
        // control characters replaced.
        $payload = "a\x00bcdef" . str_repeat('x', 100);
        $out = RmemModel::sanitizeForTerminal($payload, 8);
        $this->assertStringStartsWith('a\0bcdef', $out);
    }
}
