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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\StringDict;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Writer;

/**
 * Tests BinaryComparisonDataProvider by building minimal .rmem fixture files
 * using the Writer + StringDict classes.
 */
final class BinaryComparisonDataProviderTest extends TestCase
{
    /** @var list<string> temp files to clean up */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    private function tmpPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reli_bcdp_');
        if ($path === false) {
            $this->fail('failed to create temp file');
        }
        $this->tmpFiles[] = $path;
        return $path;
    }

    // --- loadSummaryMap ---

    #[Test]
    public function loadSummaryMapReturnsEmptyWhenNoSummarySection(): void
    {
        $path = $this->buildRmem(function (Writer $w, StringDict $dict): void {
            $w->writeSection(Format::SECTION_STRING_DICT, $dict->serialize(), $dict->count());
        });

        $provider = new BinaryComparisonDataProvider($path);
        $this->assertSame([], $provider->loadSummaryMap());
    }

    #[Test]
    public function loadSummaryMapReadsIntegerValues(): void
    {
        $path = $this->buildRmem(function (Writer $w, StringDict $dict): void {
            $keyId = $dict->intern('total_objects');
            $valId = $dict->intern('42');

            $w->writeSection(Format::SECTION_STRING_DICT, $dict->serialize(), $dict->count());

            // Summary: entry_count(u32) then pairs of (key_id(u32), value_id(u32))
            $summary = pack('V', 1)           // 1 entry
                . pack('V', $keyId)
                . pack('V', $valId);
            $w->writeSection(Format::SECTION_SUMMARY, $summary, 1);
        });

        $provider = new BinaryComparisonDataProvider($path);
        $map = $provider->loadSummaryMap();

        $this->assertArrayHasKey('total_objects', $map);
        $this->assertSame(42, $map['total_objects']);
    }

    #[Test]
    public function loadSummaryMapReadsFloatValues(): void
    {
        $path = $this->buildRmem(function (Writer $w, StringDict $dict): void {
            $keyId = $dict->intern('heap_pct');
            $valId = $dict->intern('98.5');

            $w->writeSection(Format::SECTION_STRING_DICT, $dict->serialize(), $dict->count());

            $summary = pack('V', 1)
                . pack('V', $keyId)
                . pack('V', $valId);
            $w->writeSection(Format::SECTION_SUMMARY, $summary, 1);
        });

        $provider = new BinaryComparisonDataProvider($path);
        $map = $provider->loadSummaryMap();

        $this->assertArrayHasKey('heap_pct', $map);
        $this->assertSame(98.5, $map['heap_pct']);
    }

    #[Test]
    public function loadSummaryMapSkipsNonNumericValues(): void
    {
        $path = $this->buildRmem(function (Writer $w, StringDict $dict): void {
            $keyId = $dict->intern('label');
            $valId = $dict->intern('not_a_number');

            $w->writeSection(Format::SECTION_STRING_DICT, $dict->serialize(), $dict->count());

            $summary = pack('V', 1)
                . pack('V', $keyId)
                . pack('V', $valId);
            $w->writeSection(Format::SECTION_SUMMARY, $summary, 1);
        });

        $provider = new BinaryComparisonDataProvider($path);
        $this->assertSame([], $provider->loadSummaryMap());
    }

    // --- loadTypeMap / loadClassMap ---

    #[Test]
    public function loadTypeMapAggregatesLocationsByType(): void
    {
        $path = $this->buildRmemWithLocations(
            locations: [
                ['type' => 'zend_string', 'class' => null, 'size' => 100, 'region' => 'zend_mm_heap'],
                ['type' => 'zend_string', 'class' => null, 'size' => 200, 'region' => 'zend_mm_heap'],
                ['type' => 'zend_array', 'class' => null, 'size' => 500, 'region' => 'zend_mm_heap'],
            ],
        );

        $provider = new BinaryComparisonDataProvider($path);
        $typeMap = $provider->loadTypeMap();

        $this->assertArrayHasKey('zend_string', $typeMap);
        $this->assertSame(2, $typeMap['zend_string']['count']);
        $this->assertSame(300, $typeMap['zend_string']['memory_usage']);

        $this->assertArrayHasKey('zend_array', $typeMap);
        $this->assertSame(1, $typeMap['zend_array']['count']);
        $this->assertSame(500, $typeMap['zend_array']['memory_usage']);
    }

    #[Test]
    public function loadTypeMapSortsByMemoryUsageDescending(): void
    {
        $path = $this->buildRmemWithLocations(
            locations: [
                ['type' => 'small', 'class' => null, 'size' => 10, 'region' => 'zend_mm_heap'],
                ['type' => 'large', 'class' => null, 'size' => 9999, 'region' => 'zend_mm_heap'],
                ['type' => 'medium', 'class' => null, 'size' => 500, 'region' => 'zend_mm_heap'],
            ],
        );

        $provider = new BinaryComparisonDataProvider($path);
        $typeMap = $provider->loadTypeMap();
        $keys = array_keys($typeMap);

        $this->assertSame('large', $keys[0]);
        $this->assertSame('medium', $keys[1]);
        $this->assertSame('small', $keys[2]);
    }

    #[Test]
    public function loadClassMapAggregatesLocationsByClass(): void
    {
        $path = $this->buildRmemWithLocations(
            locations: [
                ['type' => 'zend_object', 'class' => 'App\\Foo', 'size' => 120, 'region' => 'zend_mm_heap'],
                ['type' => 'zend_object', 'class' => 'App\\Foo', 'size' => 120, 'region' => 'zend_mm_heap'],
                ['type' => 'zend_object', 'class' => 'App\\Bar', 'size' => 200, 'region' => 'zend_mm_heap'],
            ],
        );

        $provider = new BinaryComparisonDataProvider($path);
        $classMap = $provider->loadClassMap();

        $this->assertArrayHasKey('App\\Foo', $classMap);
        $this->assertSame(2, $classMap['App\\Foo']['count']);
        $this->assertSame(240, $classMap['App\\Foo']['memory_usage']);

        $this->assertArrayHasKey('App\\Bar', $classMap);
        $this->assertSame(1, $classMap['App\\Bar']['count']);
        $this->assertSame(200, $classMap['App\\Bar']['memory_usage']);
    }

    #[Test]
    public function loadTypeMapFiltersIrrelevantRegions(): void
    {
        $path = $this->buildRmemWithLocations(
            locations: [
                ['type' => 'zend_string', 'class' => null, 'size' => 100, 'region' => 'zend_mm_heap'],
                ['type' => 'zend_string', 'class' => null, 'size' => 999, 'region' => 'irrelevant_region'],
            ],
        );

        $provider = new BinaryComparisonDataProvider($path);
        $typeMap = $provider->loadTypeMap();

        $this->assertArrayHasKey('zend_string', $typeMap);
        $this->assertSame(1, $typeMap['zend_string']['count']);
        $this->assertSame(100, $typeMap['zend_string']['memory_usage']);
    }

    #[Test]
    public function loadTypeMapReturnsEmptyWhenNoLocationsSection(): void
    {
        $path = $this->buildRmem(function (Writer $w, StringDict $dict): void {
            $w->writeSection(Format::SECTION_STRING_DICT, $dict->serialize(), $dict->count());
        });

        $provider = new BinaryComparisonDataProvider($path);
        $this->assertSame([], $provider->loadTypeMap());
    }

    #[Test]
    public function typeAndClassMapsAreCached(): void
    {
        $path = $this->buildRmemWithLocations(
            locations: [
                ['type' => 'zend_string', 'class' => null, 'size' => 100, 'region' => 'zend_mm_heap'],
            ],
        );

        $provider = new BinaryComparisonDataProvider($path);

        $typeMap1 = $provider->loadTypeMap();
        $typeMap2 = $provider->loadTypeMap();
        $this->assertSame($typeMap1, $typeMap2);

        $classMap1 = $provider->loadClassMap();
        $classMap2 = $provider->loadClassMap();
        $this->assertSame($classMap1, $classMap2);
    }

    // --- helpers ---

    /**
     * Build a minimal .rmem file. The callback receives the Writer and a
     * fresh StringDict to populate. The caller must write the string_dict
     * section itself (so it can intern strings before serializing).
     */
    private function buildRmem(callable $callback): string
    {
        $path = $this->tmpPath();
        $dict = new StringDict();
        $writer = new Writer($path);
        $callback($writer, $dict);
        $writer->finish();
        return $path;
    }

    /**
     * Build an .rmem file with a locations section from a simple array
     * description.
     *
     * @param list<array{type: string, class: ?string, size: int, region: string}> $locations
     */
    private function buildRmemWithLocations(array $locations): string
    {
        return $this->buildRmem(function (Writer $w, StringDict $dict) use ($locations): void {
            // First pass: intern all strings
            foreach ($locations as $loc) {
                $dict->intern($loc['type']);
                if ($loc['class'] !== null) {
                    $dict->intern($loc['class']);
                }
                $dict->intern($loc['region']);
            }

            // Write string dict first
            $w->writeSection(Format::SECTION_STRING_DICT, $dict->serialize(), $dict->count());

            // Build location rows (48 bytes each)
            $locData = '';
            foreach ($locations as $i => $loc) {
                $typeId = $dict->intern($loc['type']);
                $classId = $loc['class'] !== null
                    ? $dict->intern($loc['class'])
                    : Format::NULL_STRING_ID;
                $regionId = $dict->intern($loc['region']);

                $locData .= pack('V', $i);              // node_id (4)
                $locData .= pack('V', $typeId);          // location_type_id (4)
                $locData .= pack('V', $classId);         // class_id (4)
                $locData .= pack('P', 0x1000 + $i * 64); // address (8)
                $locData .= pack('P', $loc['size']);      // size (8)
                $locData .= pack('V', Format::NULL_STRING_ID); // string_value_id (4)
                $locData .= pack('V', 1);                // refcount (4)
                $locData .= pack('V', 0);                // type_info (4)
                $locData .= pack('V', $regionId);        // region_id (4)
                $locData .= pack('V', 0);                // bin_overhead (4)
            }

            $w->writeSection(Format::SECTION_LOCATIONS, $locData, count($locations));
        });
    }
}
