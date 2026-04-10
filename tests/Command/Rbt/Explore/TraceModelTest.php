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

use Reli\BaseTestCase;
use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;

class TraceModelTest extends BaseTestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $tmp = tempnam(sys_get_temp_dir(), 'reli_explore_');
        $this->assertNotFalse($tmp);
        $this->tmp = $tmp;
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
        parent::tearDown();
    }

    public function testLoadInternsFramesAndPreservesSampleOrder(): void
    {
        $this->writeRbt([
            $this->trace(['foo /a.php:1', 'main /m.php:5']),
            $this->trace(['bar /b.php:2', 'main /m.php:5']),
            $this->trace(['foo /a.php:1', 'main /m.php:5']),
        ]);

        $model = TraceModel::load($this->tmp);

        $this->assertSame(3, $model->sampleCount());
        $this->assertSame(10000, $model->sampling_period_us);
        $this->assertSame($this->tmp, $model->source_path);

        // 3 unique line-aware frames.
        $this->assertCount(3, $model->frame_keys);
        // 3 unique no-line frames (foo, bar, main — all distinct).
        $this->assertCount(3, $model->frame_keys_no_line);

        // Sample 0 and 2 share the same leaf frame id (intern hit).
        $this->assertSame(
            $model->samples[0][0],
            $model->samples[2][0],
            'shared frames must intern to the same id',
        );
        // Sample 0 and 1 differ in the leaf.
        $this->assertNotSame($model->samples[0][0], $model->samples[1][0]);
        // All samples share the same root frame id.
        $this->assertSame($model->samples[0][1], $model->samples[1][1]);
        $this->assertSame($model->samples[0][1], $model->samples[2][1]);
    }

    public function testLoadCollapsesNoLineForSameFunctionDifferentLine(): void
    {
        $this->writeRbt([
            $this->trace(['handler /h.php:10']),
            $this->trace(['handler /h.php:20']),
        ]);

        $model = TraceModel::load($this->tmp);

        // Two distinct with-line frames.
        $this->assertCount(2, $model->frame_keys);
        // One no-line group.
        $this->assertCount(1, $model->frame_keys_no_line);
        $this->assertSame('handler', $model->frame_keys_no_line[0]);
        // Both with-line frames map to the same no-line id.
        $this->assertSame(
            $model->no_line_map[$model->samples[0][0]],
            $model->no_line_map[$model->samples[1][0]],
        );
    }

    public function testDurationSecondsScalesWithSampleCount(): void
    {
        $this->writeRbt([
            $this->trace(['a /x.php:1']),
            $this->trace(['a /x.php:1']),
            $this->trace(['a /x.php:1']),
        ]);

        $model = TraceModel::load($this->tmp);
        // 3 samples × 10000 us = 0.030 s
        $this->assertEqualsWithDelta(0.030, $model->durationSeconds(), 1e-9);
    }

    public function testLoadMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        TraceModel::load('/nonexistent/explore.rbt');
    }

    /**
     * @param list<ParsedCallTrace> $traces
     */
    private function writeRbt(array $traces): void
    {
        $stream = fopen($this->tmp, 'wb');
        $this->assertNotFalse($stream);
        $writer = new BinaryTraceWriter($stream, 10000);
        $writer->writeHeader();
        foreach ($traces as $t) {
            $writer->writeTrace($t);
        }
        $writer->writeCheckpoint();
        $writer->writeSegmentEnd();
        unset($writer);
        fclose($stream);
    }

    /**
     * @param list<string> $frames "function /file.php:LINE", leaf-to-root
     */
    private function trace(array $frames): ParsedCallTrace
    {
        $parsed = [];
        foreach ($frames as $f) {
            // Format: "function /file:line"
            [$function, $rest] = explode(' ', $f, 2);
            $colon = strrpos($rest, ':');
            $this->assertIsInt($colon);
            $file = substr($rest, 0, $colon);
            $line = (int)substr($rest, $colon + 1);
            $parsed[] = new ParsedCallFrame($function, $file, $line);
        }
        return new ParsedCallTrace(...$parsed);
    }
}
