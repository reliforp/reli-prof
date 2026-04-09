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

        $this->assertNotEmpty($result);
    }

    public function testEncodeEmptyTraces(): void
    {
        $encoder = new PprofEncoder();
        $result = $encoder->encode([], 10000);

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

        $decompressed = gzdecode($compressed);
        $this->assertSame($raw, $decompressed);
    }

    public function testAcceptsBinaryTraceSample(): void
    {
        $samples = [
            new BinaryTraceSample(
                new ParsedCallTrace(
                    new ParsedCallFrame('func', '/file.php', 1),
                ),
                timestamp_delta_us: 10000,
                accumulated_timestamp_us: 10000,
            ),
            new BinaryTraceSample(
                new ParsedCallTrace(
                    new ParsedCallFrame('func', '/file.php', 1),
                ),
                timestamp_delta_us: 10000,
                accumulated_timestamp_us: 20000,
            ),
        ];

        $encoder = new PprofEncoder();
        $result = $encoder->encode($samples, 10000);

        $this->assertNotEmpty($result);
    }

    /**
     * Verify protobuf wire format correctness.
     *
     * Each top-level field in the Profile message should use the correct wire type:
     * - Varint fields (field_num << 3 | 0): period (field 12), duration_nanos (field 10)
     * - Length-delimited fields (field_num << 3 | 2): sample_type (field 1),
     *   sample (field 2), location (field 4), function (field 5),
     *   string_table (field 6), period_type (field 11)
     */
    public function testProtobufWireFormat(): void
    {
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('func', '/file.php', 1),
            ),
        ];

        $encoder = new PprofEncoder();
        $data = $encoder->encode($traces, 10000);

        // Parse top-level fields from protobuf and verify wire types
        $offset = 0;
        $fields = [];
        while ($offset < strlen($data)) {
            [$tag, $consumed] = Varint::decode($data, $offset);
            $offset += $consumed;
            $field_number = $tag >> 3;
            $wire_type = $tag & 0x07;
            $fields[$field_number] = $wire_type;

            if ($wire_type === 0) {
                // varint: consume value
                [$val, $consumed] = Varint::decode($data, $offset);
                $offset += $consumed;
            } elseif ($wire_type === 2) {
                // length-delimited: consume length + bytes
                [$length, $consumed] = Varint::decode($data, $offset);
                $offset += $consumed;
                $offset += $length;
            } else {
                $this->fail("Unexpected wire type {$wire_type} for field {$field_number}");
            }
        }

        // sample_type (field 1) must be length-delimited (wire type 2)
        $this->assertSame(2, $fields[1] ?? -1, 'sample_type should be length-delimited (wire type 2)');

        // sample (field 2) must be length-delimited
        $this->assertSame(2, $fields[2] ?? -1, 'sample should be length-delimited');

        // location (field 4) must be length-delimited
        $this->assertSame(2, $fields[4] ?? -1, 'location should be length-delimited');

        // function (field 5) must be length-delimited
        $this->assertSame(2, $fields[5] ?? -1, 'function should be length-delimited');

        // string_table (field 6) must be length-delimited
        $this->assertSame(2, $fields[6] ?? -1, 'string_table should be length-delimited');

        // period_type (field 11) must be length-delimited
        $this->assertSame(2, $fields[11] ?? -1, 'period_type should be length-delimited (wire type 2)');

        // period (field 12) must be varint
        $this->assertSame(0, $fields[12] ?? -1, 'period should be varint (wire type 0)');
    }

    public function testStringTableContainsWallMicroseconds(): void
    {
        $traces = [
            new ParsedCallTrace(
                new ParsedCallFrame('func', '/file.php', 1),
            ),
        ];

        $encoder = new PprofEncoder();
        $data = $encoder->encode($traces, 10000);

        // Extract string_table entries (field 6, wire type 2)
        $strings = [];
        $offset = 0;
        while ($offset < strlen($data)) {
            [$tag, $consumed] = Varint::decode($data, $offset);
            $offset += $consumed;
            $field_number = $tag >> 3;
            $wire_type = $tag & 0x07;

            if ($wire_type === 0) {
                [$val, $consumed] = Varint::decode($data, $offset);
                $offset += $consumed;
            } elseif ($wire_type === 2) {
                [$length, $consumed] = Varint::decode($data, $offset);
                $offset += $consumed;
                $value = substr($data, $offset, $length);
                $offset += $length;
                if ($field_number === 6) {
                    $strings[] = $value;
                }
            }
        }

        // Should contain "wall" and "microseconds" (not "cpu")
        $this->assertContains('wall', $strings);
        $this->assertContains('microseconds', $strings);
        $this->assertNotContains('cpu', $strings);
    }

    public function testDurationNanosFromTimestamps(): void
    {
        $samples = [
            new BinaryTraceSample(
                new ParsedCallTrace(new ParsedCallFrame('func', '/file.php', 1)),
                accumulated_timestamp_us: 0,
            ),
            new BinaryTraceSample(
                new ParsedCallTrace(new ParsedCallFrame('func', '/file.php', 1)),
                accumulated_timestamp_us: 500_000, // 500ms
            ),
        ];

        $encoder = new PprofEncoder();
        $data = $encoder->encode($samples, 10000);

        // Extract duration_nanos (field 10, varint)
        $offset = 0;
        $duration_nanos = null;
        while ($offset < strlen($data)) {
            [$tag, $consumed] = Varint::decode($data, $offset);
            $offset += $consumed;
            $field_number = $tag >> 3;
            $wire_type = $tag & 0x07;

            if ($wire_type === 0) {
                [$val, $consumed] = Varint::decode($data, $offset);
                $offset += $consumed;
                if ($field_number === 10) {
                    $duration_nanos = $val;
                }
            } elseif ($wire_type === 2) {
                [$length, $consumed] = Varint::decode($data, $offset);
                $offset += $consumed;
                $offset += $length;
            }
        }

        // 500_000 µs = 500_000_000 ns
        $this->assertSame(500_000_000, $duration_nanos);
    }
}
