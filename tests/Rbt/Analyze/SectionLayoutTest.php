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

class SectionLayoutTest extends BaseTestCase
{
    public function testParseDefaultSpecStacksOneSectionPerRow(): void
    {
        $layout = SectionLayout::parse(SectionLayout::DEFAULT_SPEC);
        $this->assertSame(
            [['self'], ['total'], ['callers'], ['callees'], ['tail']],
            $layout->rows,
        );
    }

    public function testParsePlusJoinsColumnsWithinRow(): void
    {
        $layout = SectionLayout::parse('self+total,tail');
        $this->assertSame(
            [['self', 'total'], ['tail']],
            $layout->rows,
        );
    }

    public function testParseIgnoresWhitespaceAndEmptySegments(): void
    {
        $layout = SectionLayout::parse(' self + total , , tail ');
        $this->assertSame(
            [['self', 'total'], ['tail']],
            $layout->rows,
        );
    }

    public function testParseRejectsUnknownSectionName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown section \'nope\'/');
        SectionLayout::parse('self,nope');
    }

    public function testAssembleStacksRowsWithBlankLineBetween(): void
    {
        $layout = SectionLayout::parse('self,tail');
        $lines = $layout->assemble([
            'self' => ['self line 1', 'self line 2'],
            'tail' => ['tail line 1'],
        ]);
        $this->assertSame(
            ['self line 1', 'self line 2', '', 'tail line 1'],
            $lines,
        );
    }

    public function testAssembleSkipsMissingOrEmptySections(): void
    {
        $layout = SectionLayout::parse('self,callers,tail');
        $lines = $layout->assemble([
            'self' => ['self line'],
            // 'callers' missing
            'tail' => [],
        ]);
        // Only self rendered; no dangling blank line.
        $this->assertSame(['self line'], $lines);
    }

    public function testAssemblePlusPutsColumnsSideBySide(): void
    {
        $layout = SectionLayout::parse('self+total');
        $lines = $layout->assemble([
            'self' => ['aaa', 'bb'],
            'total' => ['xx', 'y', 'zzz'],
        ]);
        // Column widths: self=3, total=3 (but last column doesn't pad).
        // Gap is two spaces between columns.
        $this->assertSame(
            [
                'aaa  xx',
                'bb   y',
                '     zzz',
            ],
            $lines,
        );
    }

    public function testAssemblePlusDropsColumnsThatHaveNoContent(): void
    {
        // A missing side section should just collapse — the remaining
        // column renders as a single-column row instead of leaving a
        // hanging empty gap.
        $layout = SectionLayout::parse('self+total');
        $lines = $layout->assemble([
            'self' => ['a', 'b'],
            // 'total' missing entirely
        ]);
        $this->assertSame(['a', 'b'], $lines);
    }

    public function testAssembleStripsTrailingWhitespaceFromRightmostColumn(): void
    {
        $layout = SectionLayout::parse('self+tail');
        $lines = $layout->assemble([
            'self' => ['left'],
            'tail' => ['right   '],
        ]);
        $this->assertSame(['left  right'], $lines);
    }
}
