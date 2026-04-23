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
}
