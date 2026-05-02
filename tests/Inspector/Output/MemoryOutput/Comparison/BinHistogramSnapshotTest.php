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

namespace Reli\Inspector\Output\MemoryOutput\Comparison;

use Reli\BaseTestCase;

class BinHistogramSnapshotTest extends BaseTestCase
{
    public function testDecodesValidPayload(): void
    {
        $json = json_encode([
            'histogram' => [
                '3' => ['count' => 100, 'total_bytes' => 3200],
                '13' => ['count' => 50, 'total_bytes' => 9600],
            ],
            'live_small_slot_count' => 150,
            'live_small_slot_bytes' => 12_800,
            'large_run_count' => 1,
            'large_run_bytes' => 8 * 4096,
            'walked_chunk_count' => 1,
            'partial' => false,
        ]);

        $decoded = BinHistogramSnapshot::decode($json);
        $this->assertNotNull($decoded);
        // Bin numbers come back as ints (PHP json_decode yields string keys
        // for stringified ints; the decoder normalises).
        $this->assertSame([3, 13], array_keys($decoded['histogram']));
        $this->assertSame(100, $decoded['histogram'][3]['count']);
        $this->assertSame(3200, $decoded['histogram'][3]['total_bytes']);
        $this->assertSame(150, $decoded['live_small_slot_count']);
        $this->assertSame(false, $decoded['partial']);
    }

    public function testReturnsNullForGarbage(): void
    {
        $this->assertNull(BinHistogramSnapshot::decode(''));
        $this->assertNull(BinHistogramSnapshot::decode('not json'));
        $this->assertNull(BinHistogramSnapshot::decode('{}'));
        $this->assertNull(BinHistogramSnapshot::decode('{"histogram": "wrong"}'));
    }

    public function testEmptyHistogramIsValid(): void
    {
        $decoded = BinHistogramSnapshot::decode(json_encode(['histogram' => []]));
        $this->assertNotNull($decoded);
        $this->assertSame([], $decoded['histogram']);
    }
}
