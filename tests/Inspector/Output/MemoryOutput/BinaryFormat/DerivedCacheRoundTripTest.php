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

namespace Reli\Inspector\Output\MemoryOutput\BinaryFormat;

use FFI;
use PHPUnit\Framework\TestCase;
use Reli\Lib\FFI\FFIHelper;

class DerivedCacheRoundTripTest extends TestCase
{
    private string $rmemPath = '';
    private string $derivedPath = '';

    protected function setUp(): void
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('FFI extension not loaded');
        }
        $this->rmemPath = tempnam(sys_get_temp_dir(), 'rmem_') . '.rmem';
        $this->derivedPath = $this->rmemPath . '.derived';
        // Create a minimal dummy rmem file so stat() works
        file_put_contents($this->rmemPath, str_repeat("\0", 128));
    }

    protected function tearDown(): void
    {
        @unlink($this->rmemPath);
        @unlink($this->derivedPath);
    }

    public function testRoundTripInt32AndInt64Sections(): void
    {
        $nc = 100;

        // Prepare test data
        $subtreeSizes = FFIHelper::new("int64_t[{$nc}]");
        $sccMap = FFIHelper::new("int32_t[{$nc}]");
        for ($i = 0; $i < $nc; $i++) {
            $subtreeSizes[$i] = ($i + 1) * 1000;
            $sccMap[$i] = $i < 10 ? 0 : -1;
        }

        $profiles = [
            [
                'id' => 0,
                'nodes' => [1, 2, 3],
                'node_count' => 3,
                'total_size' => 300,
                'ext_in' => 1,
                'ext_out' => 2,
                'class_counts' => ['Foo' => 2, 'Bar' => 1],
                'signature' => 'Foo:2, Bar:1',
                'single_owner_likelihood' => 'high',
            ],
        ];

        // Write
        $writer = DerivedCacheWriter::create($this->rmemPath);
        $this->assertNotNull($writer);

        $writer->writeInt64Section(
            DerivedCacheFormat::SECTION_SUBTREE_SIZES,
            $subtreeSizes,
            $nc,
        );
        $writer->writeInt32Section(
            DerivedCacheFormat::SECTION_SCC_NODE_MAP,
            $sccMap,
            $nc,
        );
        $profilesJson = json_encode($profiles, JSON_UNESCAPED_UNICODE);
        $writer->writeRawSection(
            DerivedCacheFormat::SECTION_SCC_PROFILES,
            $profilesJson,
            count($profiles),
        );
        $writer->writeFingerprint($this->rmemPath);
        $this->assertTrue($writer->finish($this->rmemPath));

        // Read
        $reader = DerivedCacheReader::open($this->rmemPath);
        $this->assertNotNull($reader);

        // Verify subtree sizes
        $this->assertTrue($reader->hasSection(DerivedCacheFormat::SECTION_SUBTREE_SIZES));
        $this->assertSame($nc, $reader->getSectionElementCount(DerivedCacheFormat::SECTION_SUBTREE_SIZES));
        $loadedSubtree = $reader->loadInt64Section(DerivedCacheFormat::SECTION_SUBTREE_SIZES);
        $this->assertNotNull($loadedSubtree);
        for ($i = 0; $i < $nc; $i++) {
            $this->assertSame(($i + 1) * 1000, (int)$loadedSubtree[$i], "subtree[$i]");
        }

        // Verify SCC map
        $loadedScc = $reader->loadInt32Section(DerivedCacheFormat::SECTION_SCC_NODE_MAP);
        $this->assertNotNull($loadedScc);
        for ($i = 0; $i < $nc; $i++) {
            $expected = $i < 10 ? 0 : -1;
            $this->assertSame($expected, (int)$loadedScc[$i], "scc[$i]");
        }

        // Verify profiles
        $loadedJson = $reader->getSectionData(DerivedCacheFormat::SECTION_SCC_PROFILES);
        $this->assertNotNull($loadedJson);
        $loadedProfiles = json_decode($loadedJson, true);
        $this->assertSame($profiles, $loadedProfiles);
    }

    public function testStaleRmemInvalidatesCache(): void
    {
        // Write a valid cache
        $writer = DerivedCacheWriter::create($this->rmemPath);
        $this->assertNotNull($writer);
        $data = FFIHelper::new("int32_t[1]");
        $data[0] = 42;
        $writer->writeInt32Section('test', $data, 1);
        $writer->writeFingerprint($this->rmemPath);
        $this->assertTrue($writer->finish($this->rmemPath));

        // Verify it reads
        $reader = DerivedCacheReader::open($this->rmemPath);
        $this->assertNotNull($reader);

        // Modify the rmem file (changes size → invalidates cache)
        file_put_contents($this->rmemPath, str_repeat("\0", 256));
        clearstatcache(true, $this->rmemPath);

        // Now the cache should be stale
        $reader2 = DerivedCacheReader::open($this->rmemPath);
        $this->assertNull($reader2);
    }

    public function testMissingCacheReturnsNull(): void
    {
        $reader = DerivedCacheReader::open($this->rmemPath);
        $this->assertNull($reader);
    }

    public function testMissingSectionReturnsNull(): void
    {
        $writer = DerivedCacheWriter::create($this->rmemPath);
        $this->assertNotNull($writer);
        $data = FFIHelper::new("int32_t[1]");
        $data[0] = 1;
        $writer->writeInt32Section('existing', $data, 1);
        $writer->writeFingerprint($this->rmemPath);
        $this->assertTrue($writer->finish($this->rmemPath));

        $reader = DerivedCacheReader::open($this->rmemPath);
        $this->assertNotNull($reader);
        $this->assertFalse($reader->hasSection('nonexistent'));
        $this->assertNull($reader->loadInt32Section('nonexistent'));
        $this->assertNull($reader->loadInt64Section('nonexistent'));
        $this->assertNull($reader->getSectionData('nonexistent'));
    }
}
