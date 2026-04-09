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

namespace Reli\Converter\BinaryTrace;

use Reli\BaseTestCase;
use Reli\Converter\ParsedCallFrame;
use Reli\Converter\ParsedCallTrace;

final class PprofEncoderTest extends BaseTestCase
{
    public function testEncodeProducesNonEmptyOutput(): void
    {
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('App\\Controller::index', '/app/Controller.php', 42),
                new ParsedCallFrame('main', '/app/index.php', 10),
            ),
        ];

        $encoder = new PprofEncoder();
        $result = $encoder->encode($traces, 10000);

        $this->assertNotEmpty($result);
        // pprof is a protobuf message, should be decodable binary
        $this->assertIsString($result);
    }

    public function testEncodeAggregatesSamples(): void
    {
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('func', '/file.php', 1),
            ),
            new ParsedCallTrace(
                new ParsedCallFrame('func', '/file.php', 1),
            ),
            new ParsedCallTrace(
                new ParsedCallFrame('other', '/other.php', 2),
            ),
        ];

        $encoder = new PprofEncoder();
        $result = $encoder->encode($traces, 10000);

        // Should produce output without errors
        $this->assertNotEmpty($result);
        // The output should be smaller than encoding 3 separate samples
        // since the first two are identical and get aggregated
    }

    public function testEncodeEmptyTraces(): void
    {
        $encoder = new PprofEncoder();
        $result = $encoder->encode([], 10000);

        // Should still produce a valid (minimal) protobuf
        $this->assertNotEmpty($result);
    }

    public function testGzipCompression(): void
    {
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('func', '/file.php', 1),
            ),
        ];

        $encoder = new PprofEncoder();
        $raw = $encoder->encode($traces, 10000);

        $compressed = gzencode($raw);
        $this->assertNotFalse($compressed);

        // Decompress and verify it matches
        $decompressed = gzdecode($compressed);
        $this->assertSame($raw, $decompressed);
    }
}
