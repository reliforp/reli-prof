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

use Reli\BaseTestCase;

class FrameFormatterTest extends BaseTestCase
{
    public function testPathFullLeavesFrameUnchanged(): void
    {
        $f = new FrameFormatter(FrameFormatter::PATH_FULL);
        $this->assertSame(
            'App\\Worker::loop /var/www/src/Worker.php:42',
            $f->formatFrame('App\\Worker::loop /var/www/src/Worker.php:42'),
        );
    }

    public function testPathShortReplacesDirectoryWithBasename(): void
    {
        $f = new FrameFormatter(FrameFormatter::PATH_SHORT);
        $this->assertSame(
            'App\\Worker::loop Worker.php:42',
            $f->formatFrame('App\\Worker::loop /var/www/src/Worker.php:42'),
        );
    }

    public function testPathShortPreservesOpcodeSuffix(): void
    {
        $f = new FrameFormatter(FrameFormatter::PATH_SHORT);
        $this->assertSame(
            'foo a.php:1 [ASSIGN]',
            $f->formatFrame('foo /tmp/a.php:1 [ASSIGN]'),
        );
    }

    public function testPathShortHandlesInternalPseudoPath(): void
    {
        // "<internal>" has no directory separator — basename() returns it
        // as-is, so the frame should come out unchanged.
        $f = new FrameFormatter(FrameFormatter::PATH_SHORT);
        $this->assertSame(
            'sleep <internal>:-1',
            $f->formatFrame('sleep <internal>:-1'),
        );
    }

    public function testPathShortLeavesNoLineFramesAlone(): void
    {
        // --no-line produces frames without a trailing " path:lineno".
        $f = new FrameFormatter(FrameFormatter::PATH_SHORT);
        $this->assertSame('App\\Worker::loop', $f->formatFrame('App\\Worker::loop'));
        $this->assertSame('<root>', $f->formatFrame('<root>'));
    }

    public function testCropLineNoOpWhenWidthZero(): void
    {
        $this->assertSame('abcdef', FrameFormatter::cropLine('abcdef', 0));
        $this->assertSame('abcdef', FrameFormatter::cropLine('abcdef', -1));
    }

    public function testCropLineLeavesShortLinesAlone(): void
    {
        $this->assertSame('abc', FrameFormatter::cropLine('abc', 10));
        $this->assertSame('abc', FrameFormatter::cropLine('abc', 3));
    }

    public function testCropLineClipsAndAppendsEllipsis(): void
    {
        $this->assertSame('abcd…', FrameFormatter::cropLine('abcdefghij', 5));
    }

    public function testCropLineAtWidthOneReturnsEllipsisOnly(): void
    {
        $this->assertSame('…', FrameFormatter::cropLine('abcdef', 1));
    }

    public function testCropLineCountsMultibyteCharsCorrectly(): void
    {
        // Each 日本語 char is 1 in mb_strlen; narrow terminals would
        // display them wider but our crop only guards the character
        // count — actual visual width is the terminal's concern.
        $this->assertSame('日本語…', FrameFormatter::cropLine('日本語です', 4));
    }

    public function testCropFrameDefaultAnchorIsLeftKeepsHead(): void
    {
        // Default anchor = left ⇒ keep the head, ellipsis on the right.
        // Useful when the distinguishing prefix is what you're scanning.
        $f = new FrameFormatter();
        $this->assertSame('abcd…', $f->cropFrame('abcdefghij', 5));
    }

    public function testCropFrameRightAnchorKeepsTail(): void
    {
        // Right anchor = keep the leaf. With a long FQN frame, the
        // class/method at the end survives and the namespace prefix
        // collapses into a leading ellipsis.
        $f = new FrameFormatter(FrameFormatter::PATH_FULL, FrameFormatter::ANCHOR_RIGHT);
        $this->assertSame('…fghij', $f->cropFrame('abcdefghij', 6));
    }

    public function testCropFrameRightAnchorWithRealisticFqn(): void
    {
        $f = new FrameFormatter(FrameFormatter::PATH_SHORT, FrameFormatter::ANCHOR_RIGHT);
        $frame = 'Reli\\Inspector\\MemoryDump\\FastPath\\RegionByteProvider::region /tmp/a.php:42';
        // Path shortening applies first, then the right-anchor crop
        // keeps the tail (method + shortened filename).
        $cropped = $f->cropFrame($frame, 30);
        $this->assertSame(30, mb_strlen($cropped));
        $this->assertStringStartsWith('…', $cropped);
        $this->assertStringEndsWith('a.php:42', $cropped);
    }

    public function testCropFrameBudgetZeroLeavesFrameAlone(): void
    {
        $f = new FrameFormatter();
        $this->assertSame('abcdefghij', $f->cropFrame('abcdefghij', 0));
    }

    public function testCropFrameShortEnoughIsUntouched(): void
    {
        $f = new FrameFormatter(FrameFormatter::PATH_FULL, FrameFormatter::ANCHOR_RIGHT);
        $this->assertSame('abc', $f->cropFrame('abc', 10));
    }

    public function testCropFrameAppliesPathShorteningFirst(): void
    {
        // With PATH_SHORT, "frame /long/path/File.php:10" becomes
        // "frame File.php:10" (17 chars), which fits in 20 without
        // needing further cropping.
        $f = new FrameFormatter(FrameFormatter::PATH_SHORT);
        $this->assertSame(
            'frame File.php:10',
            $f->cropFrame('frame /a/b/c/File.php:10', 20),
        );
    }

    public function testCropFrameBudgetOneReturnsEllipsis(): void
    {
        $f = new FrameFormatter(FrameFormatter::PATH_FULL, FrameFormatter::ANCHOR_RIGHT);
        $this->assertSame('…', $f->cropFrame('abcdef', 1));
    }
}
