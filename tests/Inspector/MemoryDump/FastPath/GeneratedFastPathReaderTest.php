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

namespace Reli\Inspector\MemoryDump\FastPath;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises every Generated FastPathReader version (v70-v85) to verify
 * that each implementation correctly reads struct fields from byte
 * buffers via RegionByteProvider.
 */
final class GeneratedFastPathReaderTest extends TestCase
{
    private const BASE_ADDR = 0x1000;
    private const BUFFER_SIZE = 4096;

    /** All known generated versions */
    private const VERSIONS = [
        'v70', 'v71', 'v72', 'v73', 'v74',
        'v80', 'v81', 'v82', 'v83', 'v84', 'v85',
    ];

    /**
     * @return array<string, array{string, class-string<FastPathReader>}>
     */
    public static function versionProvider(): array
    {
        $versions = [];
        foreach (self::VERSIONS as $v) {
            /** @var class-string<FastPathReader> $class */
            $class = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$v}\\FastPathReader";
            if (class_exists($class)) {
                $versions[$v] = [$v, $class];
            }
        }
        return $versions;
    }

    /**
     * Create a FastPathReader backed by a temp file containing the
     * given bytes at $baseAddr.
     *
     * @param class-string<FastPathReader> $class
     */
    private function makeReader(string $class, string $bytes, int $baseAddr = self::BASE_ADDR): FastPathReader
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'reli_fp_test_');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, $bytes);

        $fp = fopen($tmpFile, 'rb');
        $this->assertNotFalse($fp);

        $provider = new RegionByteProvider(
            [['address' => $baseAddr, 'size' => strlen($bytes), 'file_offset' => 0]],
            $fp,
        );

        return new $class($provider);
    }

    /**
     * Build a zero-filled buffer of BUFFER_SIZE and optionally poke
     * specific bytes at given offsets.
     *
     * @param array<int, string> $patches offset => packed bytes
     */
    private function buildBuffer(array $patches = []): string
    {
        $buf = str_repeat("\x00", self::BUFFER_SIZE);
        foreach ($patches as $offset => $data) {
            $buf = substr_replace($buf, $data, $offset, strlen($data));
        }
        return $buf;
    }

    // ----------------------------------------------------------------
    // Zval tests
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testZvalType(string $version, string $class): void
    {
        // zval type byte is at +8 from base in all versions
        $buf = $this->buildBuffer([8 => "\x05"]); // IS_STRING = 5
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(5, $reader->zvalType(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testZvalValuePtr(string $version, string $class): void
    {
        // zval value pointer is at +0 (8-byte little-endian)
        $ptr = 0x7F00DEADBEEF;
        $buf = $this->buildBuffer([0 => pack('P', $ptr)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($ptr, $reader->zvalValuePtr(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testZvalValueLval(string $version, string $class): void
    {
        $lval = 42;
        $buf = $this->buildBuffer([0 => pack('P', $lval)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($lval, $reader->zvalValueLval(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testZvalTypeInfo(string $version, string $class): void
    {
        // type_info is a 32-bit value at +8
        $typeInfo = 0x00040005; // type=5 with flags
        $buf = $this->buildBuffer([8 => pack('V', $typeInfo)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($typeInfo, $reader->zvalTypeInfo(self::BASE_ADDR));
    }

    // ----------------------------------------------------------------
    // Bucket tests
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testBucketValType(string $version, string $class): void
    {
        // bucket.val.u1.type is at +8 within bucket (same as zval type)
        $buf = $this->buildBuffer([8 => "\x06"]); // IS_ARRAY = 7 actually, but just testing a byte
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(6, $reader->bucketValType(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testBucketValValuePtr(string $version, string $class): void
    {
        $ptr = 0xDEADBEEF0000;
        $buf = $this->buildBuffer([0 => pack('P', $ptr)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($ptr, $reader->bucketValValuePtr(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testBucketKeyPtr(string $version, string $class): void
    {
        // key pointer is at +24 in bucket for all versions
        $ptr = 0xCAFEBABE0000;
        $buf = $this->buildBuffer([24 => pack('P', $ptr)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($ptr, $reader->bucketKeyPtr(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testBucketH(string $version, string $class): void
    {
        // h is at +16 in bucket for all versions
        $h = 0x123456789ABCDEF0;
        $buf = $this->buildBuffer([16 => pack('P', $h)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($h, $reader->bucketH(self::BASE_ADDR));
    }

    // ----------------------------------------------------------------
    // ZendArray tests
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testArrayNNumUsed(string $version, string $class): void
    {
        // nNumUsed is at +24 (32-bit) for all versions
        $buf = $this->buildBuffer([24 => pack('V', 42)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(42, $reader->arrayNNumUsed(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testArrayRefcount(string $version, string $class): void
    {
        // refcount is at +0 (32-bit) for all versions
        $buf = $this->buildBuffer([0 => pack('V', 3)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(3, $reader->arrayRefcount(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testArrayArData(string $version, string $class): void
    {
        // arData is at +16 (64-bit pointer) for all versions
        $ptr = 0xABCD00001234;
        $buf = $this->buildBuffer([16 => pack('P', $ptr)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($ptr, $reader->arrayArData(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testArrayFlags(string $version, string $class): void
    {
        // flags at +8 (32-bit)
        $buf = $this->buildBuffer([8 => pack('V', 0x0A)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(0x0A, $reader->arrayFlags(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testArrayNTableMask(string $version, string $class): void
    {
        $buf = $this->buildBuffer([12 => pack('V', 0xFFFFFFF8)]);
        $reader = $this->makeReader($class, $buf);
        // 32-bit unsigned might wrap to negative in PHP, compare as unsigned
        $this->assertSame((int)0xFFFFFFF8, $reader->arrayNTableMask(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testArrayNNumOfElements(string $version, string $class): void
    {
        $buf = $this->buildBuffer([28 => pack('V', 10)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(10, $reader->arrayNNumOfElements(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testArrayNTableSize(string $version, string $class): void
    {
        $buf = $this->buildBuffer([32 => pack('V', 8)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(8, $reader->arrayNTableSize(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testArrayTypeInfo(string $version, string $class): void
    {
        $buf = $this->buildBuffer([4 => pack('V', 0x0107)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(0x0107, $reader->arrayTypeInfo(self::BASE_ADDR));
    }

    // ----------------------------------------------------------------
    // ZendString tests
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testStringLen(string $version, string $class): void
    {
        // len is at +16 (64-bit) for all versions
        $buf = $this->buildBuffer([16 => pack('P', 42)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(42, $reader->stringLen(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testStringH(string $version, string $class): void
    {
        // h is at +8 (64-bit) for all versions
        $h = 0x0123456789ABCDEF;
        $buf = $this->buildBuffer([8 => pack('P', $h)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($h, $reader->stringH(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testStringRefcount(string $version, string $class): void
    {
        $buf = $this->buildBuffer([0 => pack('V', 7)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(7, $reader->stringRefcount(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testStringTypeInfo(string $version, string $class): void
    {
        $buf = $this->buildBuffer([4 => pack('V', 0x0106)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(0x0106, $reader->stringTypeInfo(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testStringVal(string $version, string $class): void
    {
        // stringVal reads from stringValOffset() (always 24 for all versions)
        $testStr = 'test';
        $buf = $this->buildBuffer([24 => $testStr]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($testStr, $reader->stringVal(self::BASE_ADDR, 4));
    }

    // ----------------------------------------------------------------
    // ZendObject tests
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testObjectCe(string $version, string $class): void
    {
        // ce is at +16 (64-bit pointer) for all versions
        $ptr = 0x7F0011223344;
        $buf = $this->buildBuffer([16 => pack('P', $ptr)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($ptr, $reader->objectCe(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testObjectHandle(string $version, string $class): void
    {
        // handle at +8 (32-bit)
        $buf = $this->buildBuffer([8 => pack('V', 99)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(99, $reader->objectHandle(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testObjectHandlers(string $version, string $class): void
    {
        $ptr = 0x7F00AABBCCDD;
        $buf = $this->buildBuffer([24 => pack('P', $ptr)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($ptr, $reader->objectHandlers(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testObjectProperties(string $version, string $class): void
    {
        $ptr = 0x7F0099887766;
        $buf = $this->buildBuffer([32 => pack('P', $ptr)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($ptr, $reader->objectProperties(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testObjectPropertiesTable(string $version, string $class): void
    {
        $ptr = 0x7F0055443322;
        $buf = $this->buildBuffer([40 => pack('P', $ptr)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame($ptr, $reader->objectPropertiesTable(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testObjectRefcount(string $version, string $class): void
    {
        $buf = $this->buildBuffer([0 => pack('V', 2)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(2, $reader->objectRefcount(self::BASE_ADDR));
    }

    #[DataProvider('versionProvider')]
    public function testObjectTypeInfo(string $version, string $class): void
    {
        $buf = $this->buildBuffer([4 => pack('V', 0x0108)]);
        $reader = $this->makeReader($class, $buf);
        $this->assertSame(0x0108, $reader->objectTypeInfo(self::BASE_ADDR));
    }

    // ----------------------------------------------------------------
    // Size constants
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testZvalSize(string $version, string $class): void
    {
        $reader = $this->makeReader($class, $this->buildBuffer());
        $this->assertSame(16, $reader->zvalSize());
    }

    #[DataProvider('versionProvider')]
    public function testBucketSize(string $version, string $class): void
    {
        $reader = $this->makeReader($class, $this->buildBuffer());
        $this->assertSame(32, $reader->bucketSize());
    }

    #[DataProvider('versionProvider')]
    public function testArraySize(string $version, string $class): void
    {
        $reader = $this->makeReader($class, $this->buildBuffer());
        $this->assertSame(56, $reader->arraySize());
    }

    #[DataProvider('versionProvider')]
    public function testStringHeaderSize(string $version, string $class): void
    {
        $reader = $this->makeReader($class, $this->buildBuffer());
        $this->assertSame(24, $reader->stringHeaderSize());
    }

    #[DataProvider('versionProvider')]
    public function testStringValOffset(string $version, string $class): void
    {
        $reader = $this->makeReader($class, $this->buildBuffer());
        $this->assertSame(24, $reader->stringValOffset());
    }

    #[DataProvider('versionProvider')]
    public function testObjectHeaderSize(string $version, string $class): void
    {
        $reader = $this->makeReader($class, $this->buildBuffer());
        $this->assertSame(56, $reader->objectHeaderSize());
    }

    // ----------------------------------------------------------------
    // Version-dependent behavior
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testPackedElementSize(string $version, string $class): void
    {
        $reader = $this->makeReader($class, $this->buildBuffer());
        $size = $reader->packedElementSize();
        // PHP < 8.2: packed arrays use Bucket layout (32)
        // PHP >= 8.2: packed arrays use zval layout (16)
        if (in_array($version, ['v82', 'v83', 'v84', 'v85'], true)) {
            $this->assertSame(16, $size, "PHP >= 8.2 packed element size should be 16 (zval)");
        } else {
            $this->assertSame(32, $size, "PHP < 8.2 packed element size should be 32 (bucket)");
        }
    }

    #[DataProvider('versionProvider')]
    public function testIsArrayUninitializedSemantics(string $version, string $class): void
    {
        $reader = $this->makeReader($class, $this->buildBuffer());

        // flags with bit 3 set (= 0x08)
        $flagsWithBit3 = 0x08;
        // flags without bit 3
        $flagsWithoutBit3 = 0x00;

        if (in_array($version, ['v70', 'v71', 'v72', 'v73'], true)) {
            // PHP <= 7.3: bit 3 = HASH_FLAG_INITIALIZED (1 = initialized)
            // So bit 3 set => initialized => NOT uninitialized
            $this->assertFalse(
                $reader->isArrayUninitialized($flagsWithBit3),
                "PHP <= 7.3: bit 3 set means initialized, not uninitialized"
            );
            $this->assertTrue(
                $reader->isArrayUninitialized($flagsWithoutBit3),
                "PHP <= 7.3: bit 3 unset means uninitialized"
            );
        } else {
            // PHP >= 7.4: bit 3 = HASH_FLAG_UNINITIALIZED (1 = uninitialized)
            $this->assertTrue(
                $reader->isArrayUninitialized($flagsWithBit3),
                "PHP >= 7.4: bit 3 set means uninitialized"
            );
            $this->assertFalse(
                $reader->isArrayUninitialized($flagsWithoutBit3),
                "PHP >= 7.4: bit 3 unset means initialized"
            );
        }
    }

    // ----------------------------------------------------------------
    // Unmapped address returns null
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testUnmappedAddressReturnsNull(string $version, string $class): void
    {
        $reader = $this->makeReader($class, $this->buildBuffer());
        $unmapped = 0xFFFF0000;

        $this->assertNull($reader->zvalType($unmapped));
        $this->assertNull($reader->zvalValuePtr($unmapped));
        $this->assertNull($reader->zvalValueLval($unmapped));
        $this->assertNull($reader->zvalTypeInfo($unmapped));
        $this->assertNull($reader->bucketValType($unmapped));
        $this->assertNull($reader->bucketValValuePtr($unmapped));
        $this->assertNull($reader->bucketKeyPtr($unmapped));
        $this->assertNull($reader->bucketH($unmapped));
        $this->assertNull($reader->arrayArData($unmapped));
        $this->assertNull($reader->arrayFlags($unmapped));
        $this->assertNull($reader->arrayNTableMask($unmapped));
        $this->assertNull($reader->arrayNNumUsed($unmapped));
        $this->assertNull($reader->arrayNNumOfElements($unmapped));
        $this->assertNull($reader->arrayNTableSize($unmapped));
        $this->assertNull($reader->arrayRefcount($unmapped));
        $this->assertNull($reader->arrayTypeInfo($unmapped));
        $this->assertNull($reader->stringLen($unmapped));
        $this->assertNull($reader->stringH($unmapped));
        $this->assertNull($reader->stringVal($unmapped, 4));
        $this->assertNull($reader->stringRefcount($unmapped));
        $this->assertNull($reader->stringTypeInfo($unmapped));
        $this->assertNull($reader->objectCe($unmapped));
        $this->assertNull($reader->objectHandle($unmapped));
        $this->assertNull($reader->objectHandlers($unmapped));
        $this->assertNull($reader->objectPropertiesTable($unmapped));
        $this->assertNull($reader->objectProperties($unmapped));
        $this->assertNull($reader->objectRefcount($unmapped));
        $this->assertNull($reader->objectTypeInfo($unmapped));
        $this->assertNull($reader->resolveClassEntry($unmapped));
    }

    // ----------------------------------------------------------------
    // resolveClassEntry
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testResolveClassEntry(string $version, string $class): void
    {
        // We need two regions: one for the class entry, one for the string.
        // Class entry layout:
        //   +0: refcount (4 bytes)
        //   +4: type_info (4 bytes)
        //   +8: name pointer (8 bytes) -> points to zend_string
        //   +16..+31: other fields
        //   +32: default_properties_count (4 bytes, signed int)
        //
        // zend_string layout:
        //   +0: refcount (4 bytes)
        //   +4: type_info (4 bytes)
        //   +8: h (8 bytes)
        //   +16: len (8 bytes)
        //   +24: val[] (variable)

        $ceAddr = 0x2000;
        $strAddr = 0x3000;
        $className = 'MyClass';
        $dpc = 5;

        // Build class entry buffer at ceAddr
        $ceBuf = str_repeat("\x00", 128);
        // name pointer at +8
        $ceBuf = substr_replace($ceBuf, pack('P', $strAddr), 8, 8);
        // default_properties_count at +32
        $ceBuf = substr_replace($ceBuf, pack('l', $dpc), 32, 4);

        // Build string buffer at strAddr
        $strBuf = str_repeat("\x00", 64);
        // len at +16
        $strBuf = substr_replace($strBuf, pack('P', strlen($className)), 16, 8);
        // val at +24
        $strBuf = substr_replace($strBuf, $className, 24, strlen($className));

        // Create a temp file with both regions written sequentially
        $tmpFile = tempnam(sys_get_temp_dir(), 'reli_fp_ce_');
        $this->assertNotFalse($tmpFile);
        file_put_contents($tmpFile, $ceBuf . $strBuf);

        $fp = fopen($tmpFile, 'rb');
        $this->assertNotFalse($fp);

        $provider = new RegionByteProvider(
            [
                ['address' => $ceAddr, 'size' => strlen($ceBuf), 'file_offset' => 0],
                ['address' => $strAddr, 'size' => strlen($strBuf), 'file_offset' => strlen($ceBuf)],
            ],
            $fp,
        );

        $reader = new $class($provider);
        $result = $reader->resolveClassEntry($ceAddr);

        $this->assertNotNull($result);
        $this->assertSame($className, $result['class_name']);
        $this->assertSame($dpc, $result['default_properties_count']);

        // Second call should return cached result
        $result2 = $reader->resolveClassEntry($ceAddr);
        $this->assertSame($result, $result2);
    }

    // ----------------------------------------------------------------
    // Zero-filled buffer reads (smoke test all methods return non-null)
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testAllMethodsReturnNonNullForMappedAddress(string $version, string $class): void
    {
        $buf = $this->buildBuffer();
        $reader = $this->makeReader($class, $buf);

        $this->assertNotNull($reader->zvalType(self::BASE_ADDR));
        $this->assertNotNull($reader->zvalValuePtr(self::BASE_ADDR));
        $this->assertNotNull($reader->zvalValueLval(self::BASE_ADDR));
        $this->assertNotNull($reader->zvalTypeInfo(self::BASE_ADDR));
        $this->assertNotNull($reader->bucketValType(self::BASE_ADDR));
        $this->assertNotNull($reader->bucketValValuePtr(self::BASE_ADDR));
        $this->assertNotNull($reader->bucketKeyPtr(self::BASE_ADDR));
        $this->assertNotNull($reader->bucketH(self::BASE_ADDR));
        $this->assertNotNull($reader->arrayArData(self::BASE_ADDR));
        $this->assertNotNull($reader->arrayFlags(self::BASE_ADDR));
        $this->assertNotNull($reader->arrayNTableMask(self::BASE_ADDR));
        $this->assertNotNull($reader->arrayNNumUsed(self::BASE_ADDR));
        $this->assertNotNull($reader->arrayNNumOfElements(self::BASE_ADDR));
        $this->assertNotNull($reader->arrayNTableSize(self::BASE_ADDR));
        $this->assertNotNull($reader->arrayRefcount(self::BASE_ADDR));
        $this->assertNotNull($reader->arrayTypeInfo(self::BASE_ADDR));
        $this->assertNotNull($reader->stringLen(self::BASE_ADDR));
        $this->assertNotNull($reader->stringH(self::BASE_ADDR));
        $this->assertNotNull($reader->stringVal(self::BASE_ADDR, 4));
        $this->assertNotNull($reader->stringRefcount(self::BASE_ADDR));
        $this->assertNotNull($reader->stringTypeInfo(self::BASE_ADDR));
        $this->assertNotNull($reader->objectCe(self::BASE_ADDR));
        $this->assertNotNull($reader->objectHandle(self::BASE_ADDR));
        $this->assertNotNull($reader->objectHandlers(self::BASE_ADDR));
        $this->assertNotNull($reader->objectPropertiesTable(self::BASE_ADDR));
        $this->assertNotNull($reader->objectProperties(self::BASE_ADDR));
        $this->assertNotNull($reader->objectRefcount(self::BASE_ADDR));
        $this->assertNotNull($reader->objectTypeInfo(self::BASE_ADDR));
    }
}
