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

namespace Reli\Inspector\Output\MemoryOutput\Report\Substrate;

use Reli\BaseTestCase;

class UniformSiblingDetectorTest extends BaseTestCase
{
    /**
     * @param array{kind: 'normal'|'head'|'member', annotation?: string} $decision
     */
    private function annotationOf(array $decision): string
    {
        $this->assertArrayHasKey('annotation', $decision);
        return $decision['annotation'];
    }

    public function testRunOfNumericIndexSiblingsCollapses(): void
    {
        // Real-world shape from rw3_graphql-shape: three rows of the
        // exact same retained size, paths differing only in `[N]`. The
        // first row should become the head; the others marked member.
        $sizes = [688128, 688128, 688128];
        $extras = [42, 42, 42];
        $paths = [
            '$queryResult[viewer][recentActivity][0]',
            '$queryResult[viewer][recentActivity][1]',
            '$queryResult[viewer][recentActivity][2]',
        ];

        $decisions = UniformSiblingDetector::findRuns($sizes, $extras, $paths);

        $this->assertSame('head', $decisions[0]['kind']);
        $this->assertSame('member', $decisions[1]['kind']);
        $this->assertSame('member', $decisions[2]['kind']);
        $annotation = $this->annotationOf($decisions[0]);
        $this->assertStringContainsString('+2 more similar siblings', $annotation);
        // Numeric range form is the cleaner annotation when every
        // varying token is a `[N]` integer key.
        $this->assertStringContainsString('indices [0..2]', $annotation);
    }

    public function testNonNumericVaryingSegmentEnumeratesValues(): void
    {
        // rw_logger-stack — Carbon doc_comments under different class
        // entries. Same size band, varying token is `->Carbon\X`.
        $sizes = [159907, 159870, 159860];
        $extras = [0, 0, 0];
        $paths = [
            '$class_table->Carbon\\CarbonInterface->doc_comment',
            '$class_table->Carbon\\CarbonImmutable->doc_comment',
            '$class_table->Carbon\\Carbon->doc_comment',
        ];

        $decisions = UniformSiblingDetector::findRuns($sizes, $extras, $paths);

        $this->assertSame('head', $decisions[0]['kind']);
        $this->assertSame('member', $decisions[1]['kind']);
        $this->assertSame('member', $decisions[2]['kind']);
        // Non-numeric varying tokens fall back to enumerating bare values
        // — `property` because the differing token starts with `->`.
        $annotation = $this->annotationOf($decisions[0]);
        $this->assertStringContainsString('varying property:', $annotation);
        $this->assertStringContainsString('Carbon\\CarbonInterface', $annotation);
    }

    public function testRunBreaksOnSizeOutOfTolerance(): void
    {
        // 5% default tolerance. 1000 → 1100 is 10% — must NOT collapse.
        $sizes = [1000, 1100, 1100];
        $extras = [0, 0, 0];
        $paths = ['$arr[0]', '$arr[1]', '$arr[2]'];

        $decisions = UniformSiblingDetector::findRuns($sizes, $extras, $paths);

        // Row 0 stays alone (size diff to row 1 is 10%). Rows 1+2 form
        // their own collapsed run because they're identical size.
        $this->assertSame('normal', $decisions[0]['kind']);
        $this->assertSame('head', $decisions[1]['kind']);
        $this->assertSame('member', $decisions[2]['kind']);
    }

    public function testRunBreaksOnElementCountMismatch(): void
    {
        // Same size, paths differ only in `[N]`, but element_count
        // differs — the rows describe arrays of different shape, so
        // they shouldn't collapse even though the surface looks similar.
        $sizes = [1000, 1000, 1000];
        $extras = [10, 20, 10];
        $paths = ['$arr[0]', '$arr[1]', '$arr[2]'];

        $decisions = UniformSiblingDetector::findRuns($sizes, $extras, $paths);

        $this->assertSame('normal', $decisions[0]['kind']);
        $this->assertSame('normal', $decisions[1]['kind']);
        $this->assertSame('normal', $decisions[2]['kind']);
    }

    public function testRunBreaksWhenDifferingPositionShifts(): void
    {
        // Row 0 vs row 1: differing position is segment 1 (`[0]` vs `[1]`).
        // Row 1 vs row 2: differing position is segment 3 (`->x` vs `->y`).
        // The varying-position constraint forbids extending across the
        // shift — a run must keep the same single varying token slot.
        $sizes = [1000, 1000, 1000];
        $extras = [0, 0, 0];
        $paths = ['$arr[0]->x', '$arr[1]->x', '$arr[1]->y'];

        $decisions = UniformSiblingDetector::findRuns($sizes, $extras, $paths);

        // Rows 0+1 collapse on the index.
        $this->assertSame('head', $decisions[0]['kind']);
        $this->assertSame('member', $decisions[1]['kind']);
        // Row 2's varying position is different from rows 0+1, so it
        // doesn't extend the run; it stands on its own.
        $this->assertSame('normal', $decisions[2]['kind']);
    }

    public function testRunBreaksWhenTwoSegmentsDiffer(): void
    {
        // Both `$a` vs `$b` AND `[0]` vs `[1]` differ — that's two
        // changes, which means these aren't the same shape with one
        // varying coordinate, so don't collapse.
        $sizes = [1000, 1000];
        $extras = [0, 0];
        $paths = ['$a[0]', '$b[1]'];

        $decisions = UniformSiblingDetector::findRuns($sizes, $extras, $paths);

        $this->assertSame('normal', $decisions[0]['kind']);
        $this->assertSame('normal', $decisions[1]['kind']);
    }

    public function testIdenticalPathsDoNotCollapse(): void
    {
        // Zero differing tokens between rows 0 and 1: not a varying
        // sibling pattern (would mean the same node twice). The
        // detector treats "no differing position" as "no run".
        $sizes = [1000, 1000];
        $extras = [0, 0];
        $paths = ['$a[0]', '$a[0]'];

        $decisions = UniformSiblingDetector::findRuns($sizes, $extras, $paths);

        $this->assertSame('normal', $decisions[0]['kind']);
        $this->assertSame('normal', $decisions[1]['kind']);
    }

    public function testNegativeIndicesAreStillIntegerRange(): void
    {
        // Negative integer keys are valid in PHP; the integer-range
        // annotation form should accept them.
        $sizes = [1000, 1000, 1000];
        $extras = [0, 0, 0];
        $paths = ['$arr[-1]', '$arr[0]', '$arr[1]'];

        $decisions = UniformSiblingDetector::findRuns($sizes, $extras, $paths);

        $this->assertSame('head', $decisions[0]['kind']);
        $this->assertStringContainsString('indices [-1..1]', $this->annotationOf($decisions[0]));
    }

    public function testQuotedStringKeysRenderAsEnumeratedValues(): void
    {
        // String keys like `['data']` aren't pure integer tokens, so
        // the annotation falls back to listing values rather than a
        // numeric range.
        $sizes = [1000, 1000];
        $extras = [0, 0];
        $paths = ["\$arr['alpha']", "\$arr['beta']"];

        $decisions = UniformSiblingDetector::findRuns($sizes, $extras, $paths);

        $this->assertSame('head', $decisions[0]['kind']);
        $annotation = $this->annotationOf($decisions[0]);
        $this->assertStringContainsString('varying key:', $annotation);
        $this->assertStringContainsString("'alpha'", $annotation);
    }

    public function testEmptyInputProducesEmptyOutput(): void
    {
        $this->assertSame([], UniformSiblingDetector::findRuns([], [], []));
    }

    public function testMismatchedInputLengthsDegradeToAllNormal(): void
    {
        // Defensive path: callers in this codebase pass equal-length
        // arrays, but if they didn't (length mismatch), we don't know
        // which row maps to which fact, so render everything normally
        // rather than risk a crash or a mis-collapse.
        $decisions = UniformSiblingDetector::findRuns([1, 2, 3], [0, 0], ['a', 'b', 'c']);

        $this->assertCount(3, $decisions);
        foreach ($decisions as $d) {
            $this->assertSame('normal', $d['kind']);
        }
    }
}
