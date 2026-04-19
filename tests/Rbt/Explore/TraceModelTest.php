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

    public function testLoadAppliesPathMap(): void
    {
        $this->writeRbt([
            $this->trace(['foo /var/www/html/app/Foo.php:10', 'main /var/www/html/index.php:5']),
            $this->trace(['bar /usr/share/php/Bar.php:20']),
        ]);

        $model = TraceModel::load($this->tmp, [
            '/var/www/html' => '/home/user/project',
            '/usr/share/php' => '/home/user/vendor',
        ]);

        $this->assertSame(2, $model->sampleCount());
        $this->assertContains('foo /home/user/project/app/Foo.php:10', $model->frame_keys);
        $this->assertContains('main /home/user/project/index.php:5', $model->frame_keys);
        $this->assertContains('bar /home/user/vendor/Bar.php:20', $model->frame_keys);
    }

    public function testLoadPathMapLongestPrefixWins(): void
    {
        $this->writeRbt([
            $this->trace(['foo /app/vendor/lib/Foo.php:1']),
            $this->trace(['bar /app/src/Bar.php:2']),
        ]);

        $model = TraceModel::load($this->tmp, [
            '/app' => '/local',
            '/app/vendor' => '/local-vendor',
        ]);

        $this->assertContains('foo /local-vendor/lib/Foo.php:1', $model->frame_keys);
        $this->assertContains('bar /local/src/Bar.php:2', $model->frame_keys);
    }

    public function testLoadPathMapToRelativePath(): void
    {
        $this->writeRbt([
            $this->trace(['foo /var/www/html/app/Foo.php:10']),
        ]);

        $model = TraceModel::load($this->tmp, [
            '/var/www/html' => '.',
        ]);

        $this->assertContains('foo ./app/Foo.php:10', $model->frame_keys);
    }

    public function testLoadPathMapNoMatchLeavesPathUnchanged(): void
    {
        $this->writeRbt([
            $this->trace(['foo /other/path/Foo.php:1']),
        ]);

        $model = TraceModel::load($this->tmp, [
            '/var/www/html' => '/home/user/project',
        ]);

        $this->assertContains('foo /other/path/Foo.php:1', $model->frame_keys);
    }

    public function testKeysForReturnsCorrectVariant(): void
    {
        $model = new TraceModel(
            frame_keys: ['foo /a.php:1'],
            frame_keys_no_line: ['foo'],
            no_line_map: [0],
            samples: [[0]],
            sampling_period_us: 10000,
            source_path: '/dev/null',
            frame_keys_opcode: ['foo /a.php:1 [ASSIGN]'],
            frame_keys_no_line_opcode: ['foo [ASSIGN]'],
            opcode_map: [0],
            no_line_opcode_map: [0],
        );

        $this->assertSame(['foo /a.php:1'], $model->keysFor(false, false));
        $this->assertSame(['foo'], $model->keysFor(true, false));
        $this->assertSame(['foo /a.php:1 [ASSIGN]'], $model->keysFor(false, true));
        $this->assertSame(['foo [ASSIGN]'], $model->keysFor(true, true));
    }

    public function testLoadInternsOpcodeFrames(): void
    {
        // Opcode interning happens per unique with-line key. To test distinct
        // opcodes we need different file:line combinations.
        $this->writeRbt([
            $this->traceWithOpcode([
                ['foo', '/a.php', 1, 'ASSIGN'],
                ['main', '/m.php', 5, null],
            ]),
            $this->traceWithOpcode([
                ['foo', '/a.php', 2, 'RETURN'],
                ['main', '/m.php', 5, null],
            ]),
        ]);

        $model = TraceModel::load($this->tmp);

        // With-line frames: "foo /a.php:1", "foo /a.php:2", "main /m.php:5".
        $this->assertCount(3, $model->frame_keys);

        // Opcode-qualified with-line: each gets its opcode suffix.
        $this->assertContains('foo /a.php:1 [ASSIGN]', $model->frame_keys_opcode);
        $this->assertContains('foo /a.php:2 [RETURN]', $model->frame_keys_opcode);
        // "main /m.php:5" has no opcode, so it appears without suffix.
        $this->assertContains('main /m.php:5', $model->frame_keys_opcode);

        // No-line opcode: "foo [ASSIGN]" and "foo [RETURN]" are distinct groups.
        $this->assertContains('foo [ASSIGN]', $model->frame_keys_no_line_opcode);
        $this->assertContains('foo [RETURN]', $model->frame_keys_no_line_opcode);

        // The no-line (non-opcode) view still groups them as one "foo".
        $this->assertCount(2, $model->frame_keys_no_line); // foo, main
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
     * @param list<array{string, string, int, string|null}> $frames [function, file, line, opcode]
     */
    private function traceWithOpcode(array $frames): ParsedCallTrace
    {
        $parsed = [];
        foreach ($frames as [$function, $file, $line, $opcode]) {
            $parsed[] = new ParsedCallFrame($function, $file, $line, opcode_name: $opcode);
        }
        return new ParsedCallTrace(...$parsed);
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
