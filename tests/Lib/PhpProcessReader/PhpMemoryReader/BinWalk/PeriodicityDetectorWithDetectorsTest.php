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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk;

use Reli\BaseTestCase;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector\BucketDetector;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector\ShapeDetection;

/**
 * Integration cover for the detector pipeline running inside the
 * periodicity detector — same shape grouped by fingerprint, fed through
 * the registered detectors, best label attached to the PeriodicGroup.
 */
class PeriodicityDetectorWithDetectorsTest extends BaseTestCase
{
    public function testDetectorRunsAndAttachesShapeToGroup(): void
    {
        // 32 B bin (bin_num = 3), build a Bucket-like fingerprint with
        // IS_STRING (type byte 6) at offset 8.
        // Plausible userspace pointer at +0..+7 so the tightened
        // BucketDetector accepts it. Hash is non-zero so we skip the
        // all-zero "Bucket(uninit?)" branch. Padded to 32 B so the
        // key field at +24..+31 reads as 0 (NULL key, accepted).
        $fp = pack('P', 0x00007ffe1234c000)
            . pack('CCCCV', 6, 0, 0, 0, 0)
            . pack('P', 0xdead)
            . pack('P', 0);
        $this->assertSame(32, strlen($fp));

        $bin_num = 3;
        $slots = [];
        for ($i = 0; $i < 100; $i++) {
            $slots[] = ['addr' => 0x1000 + $i * 32, 'fp' => $fp];
        }
        $groups = PeriodicityDetector::detect(
            [$bin_num => [0x1000 => $slots]],
            threshold: 32,
        );
        $this->assertCount(1, $groups);
        $this->assertNotNull($groups[0]->detection);
        $this->assertStringContainsString('Bucket', $groups[0]->detection->label);
    }

    public function testNoDetectionWhenBytesDoNotMatchAnyShape(): void
    {
        // 32 B bin with type byte 99 — outside zval whitelist; also no
        // valid zend_string header (refcount=0).
        $fp = str_repeat("\x00", 8) . chr(99) . str_repeat("\x00", 15);
        $this->assertSame(24, strlen($fp));

        $bin_num = 3;
        $slots = [];
        for ($i = 0; $i < 100; $i++) {
            $slots[] = ['addr' => 0x1000 + $i * 32, 'fp' => $fp];
        }
        $groups = PeriodicityDetector::detect(
            [$bin_num => [0x1000 => $slots]],
            threshold: 32,
        );
        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]->detection);
    }

    public function testEmptyDetectorsListDisablesInference(): void
    {
        // Plausible userspace pointer at +0..+7 so the tightened
        // BucketDetector accepts it. Hash is non-zero so we skip the
        // all-zero "Bucket(uninit?)" branch. Padded to 32 B so the
        // key field at +24..+31 reads as 0 (NULL key, accepted).
        $fp = pack('P', 0x00007ffe1234c000)
            . pack('CCCCV', 6, 0, 0, 0, 0)
            . pack('P', 0xdead)
            . pack('P', 0);
        $bin_num = 3;
        $slots = [];
        for ($i = 0; $i < 100; $i++) {
            $slots[] = ['addr' => 0x1000 + $i * 32, 'fp' => $fp];
        }
        $groups = PeriodicityDetector::detect(
            [$bin_num => [0x1000 => $slots]],
            threshold: 32,
            detectors: [],
        );
        $this->assertCount(1, $groups);
        $this->assertNull($groups[0]->detection);
    }

    public function testHigherConfidenceWinsOnTie(): void
    {
        // Synthetic detectors: one always returns LOW, one always
        // returns HIGH. Ensure the HIGH wins regardless of registration
        // order.
        $low = new class implements \Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector\ShapeDetector {
            public function name(): string
            {
                return 'low';
            }

            public function detect(string $fingerprint, int $bin_size): ?ShapeDetection
            {
                return new ShapeDetection('low_match', ShapeDetection::CONFIDENCE_LOW);
            }
        };
        $high = new class implements \Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector\ShapeDetector {
            public function name(): string
            {
                return 'high';
            }

            public function detect(string $fingerprint, int $bin_size): ?ShapeDetection
            {
                return new ShapeDetection('high_match', ShapeDetection::CONFIDENCE_HIGH);
            }
        };

        $bin_num = 3;
        $slots = [];
        for ($i = 0; $i < 50; $i++) {
            $slots[] = ['addr' => 0x1000 + $i * 32, 'fp' => 'shape'];
        }
        $groups = PeriodicityDetector::detect(
            [$bin_num => [0x1000 => $slots]],
            threshold: 32,
            detectors: [$low, $high],
        );
        $this->assertCount(1, $groups);
        $this->assertNotNull($groups[0]->detection);
        $this->assertSame('high_match', $groups[0]->detection->label);
    }
}
