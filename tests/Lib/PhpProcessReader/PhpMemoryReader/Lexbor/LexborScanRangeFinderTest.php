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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Lexbor;

use Reli\BaseTestCase;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\Process\MemoryMap\ProcessMemoryArea;
use Reli\Lib\Process\MemoryMap\ProcessMemoryAttribute;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Coverage for the range computation that decides what bytes the
 * lexbor structural fingerprinter should walk.
 *
 * The connecting plumbing (matching the binary's `rw-p` mapping +
 * picking up the immediately-following anonymous mapping for the BSS
 * tail) is the part most likely to drift on real targets, so it gets a
 * unit test independent of `LexborStateScanner` itself.
 */
final class LexborScanRangeFinderTest extends BaseTestCase
{
    public function testCollectsBinaryRwAndImmediatelyFollowingAnonBssTail(): void
    {
        // Layout:
        //   /usr/local/bin/php  rw-p  0x10000-0x14000  (16 KB)  ← file-backed .data + .bss head
        //   [anon]              rw-p  0x14000-0x16000  (8 KB)   ← BSS tail
        //   [anon]              rw-p  0x20000-0x21000           ← unrelated, must NOT be picked
        //   /usr/lib/libc.so.6  rw-p  0x30000-0x31000           ← different file, ignored
        $rwp = $this->rwAttr();
        $rxp = $this->rxAttr();
        $process_memory_map = new ProcessMemoryMap([
            new ProcessMemoryArea('10000', '14000', '0', $rwp, '00:00', 0, '/usr/local/bin/php'),
            new ProcessMemoryArea('14000', '16000', '0', $rwp, '00:00', 0, ''),
            new ProcessMemoryArea('20000', '21000', '0', $rwp, '00:00', 0, ''),
            new ProcessMemoryArea('30000', '31000', '0', $rwp, '00:00', 0, '/usr/lib/libc.so.6'),
            new ProcessMemoryArea('40000', '50000', '0', $rxp, '00:00', 0, '/usr/local/bin/php'),
        ]);

        $ranges = LexborScanRangeFinder::find(
            new ProcessSpecifier(1234),
            $this->settings(),
            $process_memory_map,
        );

        $this->assertSame(
            [
                [0x10000, 0x14000 - 0x10000],
                [0x14000, 0x16000 - 0x14000],
            ],
            $ranges,
            'expected binary rw-p plus the anon mapping that starts at its end',
        );
    }

    public function testReturnsOnlyBinaryRwWhenNoAnonBssTailFollows(): void
    {
        // No anon mapping at the file-backed end → only the rw-p VMA
        // makes it into the range list. (Plenty of distro PHPs don't
        // have a separate BSS tail; a small `.bss` fits within the
        // file-backed RW LOAD page.)
        $rwp = $this->rwAttr();
        $process_memory_map = new ProcessMemoryMap([
            new ProcessMemoryArea('10000', '14000', '0', $rwp, '00:00', 0, '/usr/local/bin/php'),
            // Anon, but starts at a different address — must NOT be picked.
            new ProcessMemoryArea('20000', '21000', '0', $rwp, '00:00', 0, ''),
        ]);

        $ranges = LexborScanRangeFinder::find(
            new ProcessSpecifier(1234),
            $this->settings(),
            $process_memory_map,
        );

        $this->assertSame(
            [[0x10000, 0x14000 - 0x10000]],
            $ranges,
        );
    }

    public function testReturnsEmptyWhenBinaryHasNoRwMapping(): void
    {
        // Read-only mappings only — `find()` finds nothing because
        // `lexbor_idna` / friends would have nowhere to live.
        $rxp = $this->rxAttr();
        $process_memory_map = new ProcessMemoryMap([
            new ProcessMemoryArea('40000', '50000', '0', $rxp, '00:00', 0, '/usr/local/bin/php'),
        ]);

        $ranges = LexborScanRangeFinder::find(
            new ProcessSpecifier(1234),
            $this->settings(),
            $process_memory_map,
        );

        $this->assertSame([], $ranges);
    }

    public function testReturnsEmptyWhenBinaryNotInMap(): void
    {
        // No `/usr/local/bin/php` mapping at all (e.g. wrong php_regex
        // or the target isn't a PHP process). `find()` returns nothing
        // and the caller no-ops the scan.
        $rwp = $this->rwAttr();
        $process_memory_map = new ProcessMemoryMap([
            new ProcessMemoryArea('10000', '14000', '0', $rwp, '00:00', 0, '/usr/lib/libc.so.6'),
        ]);

        $ranges = LexborScanRangeFinder::find(
            new ProcessSpecifier(1234),
            $this->settings(),
            $process_memory_map,
        );

        $this->assertSame([], $ranges);
    }

    private function rwAttr(): ProcessMemoryAttribute
    {
        return new ProcessMemoryAttribute(
            read: true,
            write: true,
            execute: false,
            protected: false,
        );
    }

    private function rxAttr(): ProcessMemoryAttribute
    {
        return new ProcessMemoryAttribute(
            read: true,
            write: false,
            execute: true,
            protected: false,
        );
    }

    /** @return TargetPhpSettings<'v85'> */
    private function settings(): TargetPhpSettings
    {
        return new TargetPhpSettings(
            php_regex: '/php$',
            php_version: 'v85',
        );
    }
}
