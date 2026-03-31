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

namespace Reli\Converter\Speedscope;

use Reli\BaseTestCase;
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;
use Reli\Converter\Speedscope\Settings\SpeedscopeConverterSettings;
use Reli\Converter\Speedscope\Settings\Utf8ErrorHandlingType;

class SpeedscopeConverterTest extends BaseTestCase
{
    private function createSettings(
        Utf8ErrorHandlingType $type = Utf8ErrorHandlingType::Ignore
    ): SpeedscopeConverterSettings {
        return new SpeedscopeConverterSettings($type);
    }

    public function testEmptyInput(): void
    {
        $converter = new SpeedscopeConverter();
        $result = $converter->collectFrames([], $this->createSettings());

        $this->assertSame('https://www.speedscope.app/file-format-schema.json', $result['$schema']);
        $this->assertSame([], $result['shared']['frames']);
        $this->assertSame([], $result['profiles'][0]['samples']);
        $this->assertSame([], $result['profiles'][0]['weights']);
        $this->assertSame(0, $result['profiles'][0]['startValue']);
        $this->assertSame(0, $result['profiles'][0]['endValue']);
        $this->assertSame('sampled', $result['profiles'][0]['type']);
        $this->assertSame('php profile', $result['profiles'][0]['name']);
        $this->assertSame('none', $result['profiles'][0]['unit']);
    }

    public function testSingleTrace(): void
    {
        $converter = new SpeedscopeConverter();
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('func_a', '/a.php', 10),
                new ParsedCallFrame('func_b', '/b.php', 20),
            ),
        ];
        $result = $converter->collectFrames($traces, $this->createSettings());

        $this->assertCount(2, $result['shared']['frames']);
        $this->assertSame(
            ['name' => 'func_a', 'file' => '/a.php', 'line' => 10],
            $result['shared']['frames'][0]
        );
        $this->assertSame(
            ['name' => 'func_b', 'file' => '/b.php', 'line' => 20],
            $result['shared']['frames'][1]
        );

        // samples are reversed (call stack order)
        $this->assertSame([[1, 0]], $result['profiles'][0]['samples']);
        $this->assertSame([1], $result['profiles'][0]['weights']);
        $this->assertSame(1, $result['profiles'][0]['endValue']);
    }

    public function testDuplicateFramesAreDeduped(): void
    {
        $converter = new SpeedscopeConverter();
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('func_a', '/a.php', 10),
            ),
            new ParsedCallTrace(
                new ParsedCallFrame('func_a', '/a.php', 10),
            ),
        ];
        $result = $converter->collectFrames($traces, $this->createSettings());

        // Only one unique frame
        $this->assertCount(1, $result['shared']['frames']);
        // Two samples, both referencing index 0
        $this->assertSame([[0], [0]], $result['profiles'][0]['samples']);
        $this->assertSame([1, 1], $result['profiles'][0]['weights']);
        $this->assertSame(2, $result['profiles'][0]['endValue']);
    }

    public function testMultipleTracesWithMixedFrames(): void
    {
        $converter = new SpeedscopeConverter();
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('func_a', '/a.php', 10),
                new ParsedCallFrame('func_b', '/b.php', 20),
            ),
            new ParsedCallTrace(
                new ParsedCallFrame('func_a', '/a.php', 10),
                new ParsedCallFrame('func_c', '/c.php', 30),
            ),
        ];
        $result = $converter->collectFrames($traces, $this->createSettings());

        // 3 unique frames: func_a, func_b, func_c
        $this->assertCount(3, $result['shared']['frames']);
        $this->assertSame(2, $result['profiles'][0]['endValue']);
    }

    public function testUtf8FailModeThrowsOnInvalidUtf8(): void
    {
        $converter = new SpeedscopeConverter();
        $invalidUtf8 = "func_\x80\x81";
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame($invalidUtf8, '/a.php', 10),
            ),
        ];

        $this->expectException(SpeedscopeConverterException::class);
        $converter->collectFrames($traces, $this->createSettings(Utf8ErrorHandlingType::Fail));
    }

    public function testUtf8IgnoreModeHandlesInvalidUtf8(): void
    {
        $converter = new SpeedscopeConverter();
        $invalidUtf8 = "func_\x80\x81";
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame($invalidUtf8, '/a.php', 10),
            ),
        ];

        $result = $converter->collectFrames($traces, $this->createSettings(Utf8ErrorHandlingType::Ignore));
        $this->assertCount(1, $result['shared']['frames']);
    }

    public function testUtf8SubstituteModeHandlesInvalidUtf8(): void
    {
        $converter = new SpeedscopeConverter();
        $invalidUtf8 = "func_\x80";
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame($invalidUtf8, '/a.php', 10),
            ),
        ];

        $result = $converter->collectFrames($traces, $this->createSettings(Utf8ErrorHandlingType::Substitute));
        $this->assertCount(1, $result['shared']['frames']);
        // The frame data retains the original string; substitute applies during JSON encoding for dedup
        $this->assertSame($invalidUtf8, $result['shared']['frames'][0]['name']);
    }

    public function testExceptionMessageContainsOriginalContext(): void
    {
        $converter = new SpeedscopeConverter();
        $invalidUtf8 = "func_\x80";
        $context = new \Reli\Converter\PhpSpyCompatibleDataContext(42);
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame($invalidUtf8, '/a.php', 10, $context),
            ),
        ];

        try {
            $converter->collectFrames($traces, $this->createSettings(Utf8ErrorHandlingType::Fail));
            $this->fail('Expected SpeedscopeConverterException');
        } catch (SpeedscopeConverterException $e) {
            $this->assertStringContainsString('line:42', $e->getMessage());
        }
    }

    public function testExceptionMessageWithoutContext(): void
    {
        $converter = new SpeedscopeConverter();
        $invalidUtf8 = "func_\x80";
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame($invalidUtf8, '/a.php', 10, null),
            ),
        ];

        try {
            $converter->collectFrames($traces, $this->createSettings(Utf8ErrorHandlingType::Fail));
            $this->fail('Expected SpeedscopeConverterException');
        } catch (SpeedscopeConverterException $e) {
            $this->assertStringContainsString('unknown location', $e->getMessage());
        }
    }
}
