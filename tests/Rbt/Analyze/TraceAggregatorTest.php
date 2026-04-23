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
use Reli\Converter\BinaryTrace\BinaryTraceSample;
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;

class TraceAggregatorTest extends BaseTestCase
{
    public function testWrapPatternHandlesNullAndEmpty(): void
    {
        $this->assertNull(TraceAggregator::wrapPattern(null));
        $this->assertNull(TraceAggregator::wrapPattern(''));
    }

    public function testWrapPatternAddsDelimitersAndEscapesHash(): void
    {
        $this->assertSame('#foo#', TraceAggregator::wrapPattern('foo'));
        $this->assertSame('#a\\#b#', TraceAggregator::wrapPattern('a#b'));
    }

    public function testSelfTimeCountsLeafFrameOnly(): void
    {
        $samples = [
            $this->makeSample(['leaf_a', 'middle', 'root']),
            $this->makeSample(['leaf_b', 'middle', 'root']),
            $this->makeSample(['leaf_a', 'other', 'root']),
        ];
        $result = (new TraceAggregator(no_line: true))->aggregate($samples);

        $this->assertSame(3, $result->sample_count);
        $this->assertSame(3, $result->matched_samples);
        // Two samples land on leaf_a, one on leaf_b.
        $this->assertSame(2, $result->self_counts['leaf_a']);
        $this->assertSame(1, $result->self_counts['leaf_b']);
    }

    public function testTotalTimeCountsEachFrameOnceDedupedPerSample(): void
    {
        $samples = [
            $this->makeSample(['recursive', 'recursive', 'caller']),
            $this->makeSample(['leaf', 'caller']),
        ];
        $result = (new TraceAggregator(no_line: true))->aggregate($samples);

        // recursive appears twice in sample 1 but is counted once
        // (deduped per sample), and not at all in sample 2.
        $this->assertSame(1, $result->total_counts['recursive']);
        // caller appears in both samples.
        $this->assertSame(2, $result->total_counts['caller']);
        // leaf appears only in sample 2.
        $this->assertSame(1, $result->total_counts['leaf']);
    }

    public function testNoLineFalseIncludesFileAndLine(): void
    {
        $samples = [
            $this->makeSample(
                [['fn' => 'foo', 'file' => '/a.php', 'line' => 1]],
            ),
        ];
        $result = (new TraceAggregator(no_line: false))->aggregate($samples);

        $this->assertSame(['foo /a.php:1' => 1], $result->self_counts);
    }

    public function testHidePatternFiltersFramesBeforeCounting(): void
    {
        $samples = [
            $this->makeSample(['hidden_x', 'real_leaf', 'caller']),
        ];
        $result = (new TraceAggregator(
            no_line: true,
            hide_re: TraceAggregator::wrapPattern('^hidden_'),
        ))->aggregate($samples);

        // hidden_x is dropped, so real_leaf becomes the leaf.
        $this->assertSame(1, $result->matched_samples);
        $this->assertSame(['real_leaf' => 1], $result->self_counts);
        $this->assertArrayNotHasKey('hidden_x', $result->total_counts);
    }

    public function testHideAllFramesSkipsTheSample(): void
    {
        $samples = [
            $this->makeSample(['hidden_a', 'hidden_b']),
            $this->makeSample(['kept']),
        ];
        $result = (new TraceAggregator(
            no_line: true,
            hide_re: TraceAggregator::wrapPattern('^hidden_'),
        ))->aggregate($samples);

        $this->assertSame(2, $result->sample_count);
        // Sample 1's stack is empty after hide → skipped (matched_samples = 1).
        $this->assertSame(1, $result->matched_samples);
        $this->assertSame(['kept' => 1], $result->self_counts);
    }

    public function testMatchPatternKeepsOnlySamplesContainingMatchingFrame(): void
    {
        $samples = [
            $this->makeSample(['leaf_a', 'target', 'root']),
            $this->makeSample(['leaf_b', 'other', 'root']),
            $this->makeSample(['target', 'middle']),
        ];
        $result = (new TraceAggregator(
            no_line: true,
            match_re: TraceAggregator::wrapPattern('^target$'),
        ))->aggregate($samples);

        $this->assertSame(3, $result->sample_count);
        // Samples 1 and 3 contain "target", sample 2 doesn't.
        $this->assertSame(2, $result->matched_samples);
        $this->assertSame(1, $result->self_counts['leaf_a']);
        $this->assertSame(1, $result->self_counts['target']);
        $this->assertArrayNotHasKey('leaf_b', $result->self_counts);
    }

    public function testCallersAttributesToImmediateCallerOfLeafmostMatch(): void
    {
        // Stack order: index 0 is the leaf, last index is the root.
        $samples = [
            // First match (leafmost) is "target" at index 1; immediate
            // caller is "caller_a" at index 2.
            $this->makeSample(['leaf', 'target', 'caller_a', 'root']),
            // Same shape, different caller.
            $this->makeSample(['leaf', 'target', 'caller_b', 'root']),
            // No match → contributes nothing to caller_counts.
            $this->makeSample(['leaf', 'other', 'caller_a']),
            // Match is the rootmost frame → caller is the synthetic <root>.
            $this->makeSample(['leaf', 'target']),
        ];
        $result = (new TraceAggregator(
            no_line: true,
            callers_re: TraceAggregator::wrapPattern('^target$'),
        ))->aggregate($samples);

        $this->assertSame(1, $result->caller_counts['caller_a']);
        $this->assertSame(1, $result->caller_counts['caller_b']);
        $this->assertSame(1, $result->caller_counts['<root>']);
    }

    public function testCalleesAttributesToImmediateCalleeOfRootmostMatch(): void
    {
        $samples = [
            // Rootmost "target" is at index 2; immediate callee is "callee_x" at index 1.
            $this->makeSample(['leaf', 'callee_x', 'target', 'root']),
            // Match is the leaf → callee is the synthetic <leaf>.
            $this->makeSample(['target', 'middle', 'root']),
        ];
        $result = (new TraceAggregator(
            no_line: true,
            callees_re: TraceAggregator::wrapPattern('^target$'),
        ))->aggregate($samples);

        $this->assertSame(1, $result->callee_counts['callee_x']);
        $this->assertSame(1, $result->callee_counts['<leaf>']);
    }

    public function testTailBufferKeepsLastNSamplesAsRingBuffer(): void
    {
        $samples = [
            $this->makeSample(['s1']),
            $this->makeSample(['s2']),
            $this->makeSample(['s3']),
            $this->makeSample(['s4']),
        ];
        $result = (new TraceAggregator(
            no_line: true,
            last_count: 2,
        ))->aggregate($samples);

        $this->assertCount(2, $result->tail);
        $this->assertSame(['s3'], $result->tail[0]['frames']);
        $this->assertSame(['s4'], $result->tail[1]['frames']);
    }

    public function testLastCountZeroLeavesTailBufferEmpty(): void
    {
        $samples = [$this->makeSample(['only'])];
        $result = (new TraceAggregator(no_line: true))->aggregate($samples);
        $this->assertSame([], $result->tail);
    }

    public function testLastDepthKeepsOnlyLeafMostFrames(): void
    {
        $samples = [
            $this->makeSample(['leaf', 'mid', 'outer', 'root']),
        ];
        $result = (new TraceAggregator(
            no_line: true,
            last_count: 1,
            last_depth: 2,
        ))->aggregate($samples);

        // Only the 2 leaf-most frames survive in the tail. Aggregation
        // tables are unchanged — last_depth is tail-only.
        $this->assertSame(['leaf', 'mid'], $result->tail[0]['frames']);
        $this->assertSame(1, $result->total_counts['root']);
        $this->assertSame(1, $result->total_counts['outer']);
    }

    public function testLastDepthZeroLeavesFullStack(): void
    {
        $samples = [
            $this->makeSample(['leaf', 'mid', 'root']),
        ];
        $result = (new TraceAggregator(
            no_line: true,
            last_count: 1,
            last_depth: 0,
        ))->aggregate($samples);

        $this->assertSame(['leaf', 'mid', 'root'], $result->tail[0]['frames']);
    }

    public function testLastDepthLargerThanStackJustReturnsStack(): void
    {
        $samples = [
            $this->makeSample(['leaf', 'root']),
        ];
        $result = (new TraceAggregator(
            no_line: true,
            last_count: 1,
            last_depth: 10,
        ))->aggregate($samples);

        $this->assertSame(['leaf', 'root'], $result->tail[0]['frames']);
    }

    public function testWithOpcodeAppendsOpcodeToKey(): void
    {
        $samples = [
            $this->makeSample([
                ['fn' => 'foo', 'file' => '/a.php', 'line' => 1, 'opcode' => 'ASSIGN'],
                ['fn' => 'bar', 'file' => '/b.php', 'line' => 2, 'opcode' => null],
            ]),
        ];
        $result = (new TraceAggregator(no_line: false, with_opcode: true))->aggregate($samples);

        $this->assertArrayHasKey('foo /a.php:1 [ASSIGN]', $result->self_counts);
        $this->assertArrayHasKey('bar /b.php:2', $result->total_counts);
        $this->assertArrayNotHasKey('foo /a.php:1', $result->self_counts);
    }

    public function testWithOpcodeNoLineAppendsOpcodeToFunctionName(): void
    {
        $samples = [
            $this->makeSample([
                ['fn' => 'foo', 'file' => '/a.php', 'line' => 1, 'opcode' => 'RETURN'],
            ]),
        ];
        $result = (new TraceAggregator(no_line: true, with_opcode: true))->aggregate($samples);

        $this->assertArrayHasKey('foo [RETURN]', $result->self_counts);
        $this->assertArrayNotHasKey('foo', $result->self_counts);
    }

    public function testEmptySampleStreamReturnsZeros(): void
    {
        $result = (new TraceAggregator())->aggregate([]);
        $this->assertSame(0, $result->sample_count);
        $this->assertSame(0, $result->matched_samples);
        $this->assertSame([], $result->self_counts);
        $this->assertSame([], $result->total_counts);
        $this->assertSame([], $result->caller_counts);
        $this->assertSame([], $result->callee_counts);
        $this->assertSame([], $result->tail);
    }

    /**
     * @param list<string|array{fn:string, file:string, line:int, opcode?:string|null}> $frames
     */
    private function makeSample(array $frames): BinaryTraceSample
    {
        $parsed_frames = [];
        foreach ($frames as $f) {
            if (is_string($f)) {
                $parsed_frames[] = new ParsedCallFrame(
                    function_name: $f,
                    file_name: '/test.php',
                    lineno: 1,
                );
            } else {
                $parsed_frames[] = new ParsedCallFrame(
                    function_name: $f['fn'],
                    file_name: $f['file'],
                    lineno: $f['line'],
                    opcode_name: $f['opcode'] ?? null,
                );
            }
        }
        return new BinaryTraceSample(
            new ParsedCallTrace(...$parsed_frames),
        );
    }
}
