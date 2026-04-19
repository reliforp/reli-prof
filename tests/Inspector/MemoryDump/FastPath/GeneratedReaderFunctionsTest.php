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
 * Exercises every auto-generated namespace function (not class methods)
 * across all 11 PHP version variants (v70-v85).
 *
 * Each reader file defines free functions like zval_value_ptr($buf, $off),
 * bucket_key($buf, $off), etc. This test builds byte buffers with known
 * values at the offsets defined by the corresponding Layout class, then
 * verifies each function reads back the expected value.
 */
final class GeneratedReaderFunctionsTest extends TestCase
{
    private const VERSIONS = [
        'v70', 'v71', 'v72', 'v73', 'v74',
        'v80', 'v81', 'v82', 'v83', 'v84', 'v85',
    ];

    /**
     * @return array<string, array{string}>
     */
    public static function versionProvider(): array
    {
        $result = [];
        foreach (self::VERSIONS as $v) {
            $result[$v] = [$v];
        }
        return $result;
    }

    /**
     * Build a zero-filled buffer and poke packed data at specific offsets.
     *
     * @param array<int, string> $patches offset => packed bytes
     */
    private function buildBuffer(int $size = 256, array $patches = []): string
    {
        $buf = str_repeat("\x00", $size);
        foreach ($patches as $offset => $data) {
            $buf = substr_replace($buf, $data, $offset, strlen($data));
        }
        return $buf;
    }

    /**
     * Ensure the reader file is loaded so its namespace functions exist.
     */
    private function requireReader(string $version, string $readerFile): void
    {
        $path = __DIR__ . "/../../../../src/Inspector/MemoryDump/FastPath/Generated/{$version}/{$readerFile}";
        require_once $path;
    }

    // ----------------------------------------------------------------
    // ZvalReader functions
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testZvalValuePtr(string $version): void
    {
        $this->requireReader($version, 'ZvalReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZvalLayout';

        $ptr = 0x7F00DEADBEEF;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_VALUE_PTR => pack('P', $ptr)]);

        $fn = $ns . '\\zval_value_ptr';
        $this->assertSame($ptr, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZvalValueLval(string $version): void
    {
        $this->requireReader($version, 'ZvalReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZvalLayout';

        $lval = 42;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_VALUE_LVAL => pack('P', $lval)]);

        $fn = $ns . '\\zval_value_lval';
        $this->assertSame($lval, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZvalValueDval(string $version): void
    {
        $this->requireReader($version, 'ZvalReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZvalLayout';

        $rawBits = 0x4045000000000000; // 42.0 in IEEE 754
        $buf = $this->buildBuffer(256, [$layout::OFFSET_VALUE_DVAL => pack('P', $rawBits)]);

        $fn = $ns . '\\zval_value_dval';
        $this->assertSame($rawBits, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZvalU1TypeInfo(string $version): void
    {
        $this->requireReader($version, 'ZvalReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZvalLayout';

        $typeInfo = 0x00040005;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_U1_TYPE_INFO => pack('V', $typeInfo)]);

        $fn = $ns . '\\zval_u1_type_info';
        $this->assertSame($typeInfo, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZvalU1VType(string $version): void
    {
        $this->requireReader($version, 'ZvalReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZvalLayout';

        $buf = $this->buildBuffer(256);
        $buf[$layout::OFFSET_U1_V_TYPE] = chr(5);

        $fn = $ns . '\\zval_u1_v_type';
        $this->assertSame(5, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZvalU1VTypeFlags(string $version): void
    {
        $this->requireReader($version, 'ZvalReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZvalLayout';

        $buf = $this->buildBuffer(256);
        $buf[$layout::OFFSET_U1_V_TYPE_FLAGS] = chr(0xAB);

        $fn = $ns . '\\zval_u1_v_type_flags';
        $this->assertSame(0xAB, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZvalU2Next(string $version): void
    {
        $this->requireReader($version, 'ZvalReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZvalLayout';

        $next = 0x12345678;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_U2_NEXT => pack('V', $next)]);

        $fn = $ns . '\\zval_u2_next';
        $this->assertSame($next, $fn($buf, 0));
    }

    // ----------------------------------------------------------------
    // BucketReader functions
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testBucketValValuePtr(string $version): void
    {
        $this->requireReader($version, 'BucketReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\BucketLayout';

        $ptr = 0xDEADBEEF0000;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_VAL_VALUE_PTR => pack('P', $ptr)]);

        $fn = $ns . '\\bucket_val_value_ptr';
        $this->assertSame($ptr, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testBucketValU1VType(string $version): void
    {
        $this->requireReader($version, 'BucketReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\BucketLayout';

        $buf = $this->buildBuffer(256);
        $buf[$layout::OFFSET_VAL_U1_V_TYPE] = chr(6);

        $fn = $ns . '\\bucket_val_u1_v_type';
        $this->assertSame(6, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testBucketValU1VTypeFlags(string $version): void
    {
        $this->requireReader($version, 'BucketReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\BucketLayout';

        $buf = $this->buildBuffer(256);
        $buf[$layout::OFFSET_VAL_U1_V_TYPE_FLAGS] = chr(0xCD);

        $fn = $ns . '\\bucket_val_u1_v_type_flags';
        $this->assertSame(0xCD, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testBucketValU1TypeInfo(string $version): void
    {
        $this->requireReader($version, 'BucketReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\BucketLayout';

        $typeInfo = 0x00040006;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_VAL_U1_TYPE_INFO => pack('V', $typeInfo)]);

        $fn = $ns . '\\bucket_val_u1_type_info';
        $this->assertSame($typeInfo, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testBucketH(string $version): void
    {
        $this->requireReader($version, 'BucketReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\BucketLayout';

        $h = 0x123456789ABCDEF0;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_H => pack('P', $h)]);

        $fn = $ns . '\\bucket_h';
        $this->assertSame($h, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testBucketKey(string $version): void
    {
        $this->requireReader($version, 'BucketReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\BucketLayout';

        $ptr = 0xCAFEBABE0000;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_KEY => pack('P', $ptr)]);

        $fn = $ns . '\\bucket_key';
        $this->assertSame($ptr, $fn($buf, 0));
    }

    // ----------------------------------------------------------------
    // ZendArrayReader functions
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testZendArrayGcRefcount(string $version): void
    {
        $this->requireReader($version, 'ZendArrayReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendArrayLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_GC_REFCOUNT => pack('V', 3)]);

        $fn = $ns . '\\zendarray_gc_refcount';
        $this->assertSame(3, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendArrayGcTypeInfo(string $version): void
    {
        $this->requireReader($version, 'ZendArrayReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendArrayLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_GC_TYPE_INFO => pack('V', 0x0107)]);

        $fn = $ns . '\\zendarray_gc_type_info';
        $this->assertSame(0x0107, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendArrayFlags(string $version): void
    {
        $this->requireReader($version, 'ZendArrayReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendArrayLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_FLAGS => pack('V', 0x0A)]);

        $fn = $ns . '\\zendarray_flags';
        $this->assertSame(0x0A, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendArrayNTableMask(string $version): void
    {
        $this->requireReader($version, 'ZendArrayReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendArrayLayout';

        $mask = 0x0000FFF8;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_N_TABLE_MASK => pack('V', $mask)]);

        $fn = $ns . '\\zendarray_n_table_mask';
        $this->assertSame($mask, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendArrayArData(string $version): void
    {
        $this->requireReader($version, 'ZendArrayReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendArrayLayout';

        $ptr = 0xABCD00001234;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_AR_DATA => pack('P', $ptr)]);

        $fn = $ns . '\\zendarray_ar_data';
        $this->assertSame($ptr, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendArrayNNumUsed(string $version): void
    {
        $this->requireReader($version, 'ZendArrayReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendArrayLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_N_NUM_USED => pack('V', 42)]);

        $fn = $ns . '\\zendarray_n_num_used';
        $this->assertSame(42, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendArrayNNumOfElements(string $version): void
    {
        $this->requireReader($version, 'ZendArrayReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendArrayLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_N_NUM_OF_ELEMENTS => pack('V', 10)]);

        $fn = $ns . '\\zendarray_n_num_of_elements';
        $this->assertSame(10, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendArrayNTableSize(string $version): void
    {
        $this->requireReader($version, 'ZendArrayReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendArrayLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_N_TABLE_SIZE => pack('V', 8)]);

        $fn = $ns . '\\zendarray_n_table_size';
        $this->assertSame(8, $fn($buf, 0));
    }

    // ----------------------------------------------------------------
    // ZendStringReader functions
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testZendStringGcRefcount(string $version): void
    {
        $this->requireReader($version, 'ZendStringReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendStringLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_GC_REFCOUNT => pack('V', 7)]);

        $fn = $ns . '\\zendstring_gc_refcount';
        $this->assertSame(7, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendStringGcTypeInfo(string $version): void
    {
        $this->requireReader($version, 'ZendStringReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendStringLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_GC_TYPE_INFO => pack('V', 0x0106)]);

        $fn = $ns . '\\zendstring_gc_type_info';
        $this->assertSame(0x0106, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendStringH(string $version): void
    {
        $this->requireReader($version, 'ZendStringReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendStringLayout';

        $h = 0x0123456789ABCDEF;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_H => pack('P', $h)]);

        $fn = $ns . '\\zendstring_h';
        $this->assertSame($h, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendStringLen(string $version): void
    {
        $this->requireReader($version, 'ZendStringReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendStringLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_LEN => pack('P', 42)]);

        $fn = $ns . '\\zendstring_len';
        $this->assertSame(42, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendStringValOffset(string $version): void
    {
        $this->requireReader($version, 'ZendStringReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendStringLayout';

        $buf = $this->buildBuffer(256);
        $buf[$layout::OFFSET_VAL_OFFSET] = chr(0x41); // 'A'

        $fn = $ns . '\\zendstring_val_offset';
        $this->assertSame(0x41, $fn($buf, 0));
    }

    // ----------------------------------------------------------------
    // ZendObjectReader functions
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testZendObjectGcRefcount(string $version): void
    {
        $this->requireReader($version, 'ZendObjectReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendObjectLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_GC_REFCOUNT => pack('V', 2)]);

        $fn = $ns . '\\zendobject_gc_refcount';
        $this->assertSame(2, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendObjectGcTypeInfo(string $version): void
    {
        $this->requireReader($version, 'ZendObjectReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendObjectLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_GC_TYPE_INFO => pack('V', 0x0108)]);

        $fn = $ns . '\\zendobject_gc_type_info';
        $this->assertSame(0x0108, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendObjectHandle(string $version): void
    {
        $this->requireReader($version, 'ZendObjectReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendObjectLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_HANDLE => pack('V', 99)]);

        $fn = $ns . '\\zendobject_handle';
        $this->assertSame(99, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendObjectCe(string $version): void
    {
        $this->requireReader($version, 'ZendObjectReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendObjectLayout';

        $ptr = 0x7F0011223344;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_CE => pack('P', $ptr)]);

        $fn = $ns . '\\zendobject_ce';
        $this->assertSame($ptr, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendObjectHandlers(string $version): void
    {
        $this->requireReader($version, 'ZendObjectReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendObjectLayout';

        $ptr = 0x7F00AABBCCDD;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_HANDLERS => pack('P', $ptr)]);

        $fn = $ns . '\\zendobject_handlers';
        $this->assertSame($ptr, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendObjectPropertiesTable(string $version): void
    {
        $this->requireReader($version, 'ZendObjectReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendObjectLayout';

        $ptr = 0x7F0055443322;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_PROPERTIES_TABLE => pack('P', $ptr)]);

        $fn = $ns . '\\zendobject_properties_table';
        $this->assertSame($ptr, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendObjectProperties(string $version): void
    {
        $this->requireReader($version, 'ZendObjectReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendObjectLayout';

        $ptr = 0x7F0099887766;
        $buf = $this->buildBuffer(256, [$layout::OFFSET_PROPERTIES => pack('P', $ptr)]);

        $fn = $ns . '\\zendobject_properties';
        $this->assertSame($ptr, $fn($buf, 0));
    }

    // ----------------------------------------------------------------
    // ZendClassEntryReader functions
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testZendClassEntryName(string $version): void
    {
        $this->requireReader($version, 'ZendClassEntryReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendClassEntryLayout';

        $ptr = 0x7F00ABCDEF01;
        $buf = $this->buildBuffer(512, [$layout::OFFSET_NAME => pack('P', $ptr)]);

        $fn = $ns . '\\zendclassentry_name';
        $this->assertSame($ptr, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendClassEntryDefaultPropertiesCount(string $version): void
    {
        $this->requireReader($version, 'ZendClassEntryReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendClassEntryLayout';

        $buf = $this->buildBuffer(512, [$layout::OFFSET_DEFAULT_PROPERTIES_COUNT => pack('l', 5)]);

        $fn = $ns . '\\zendclassentry_default_properties_count';
        $this->assertSame(5, $fn($buf, 0));
    }

    // ----------------------------------------------------------------
    // ZendRefcountedReader functions
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testZendRefcountedGcRefcount(string $version): void
    {
        $this->requireReader($version, 'ZendRefcountedReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendRefcountedLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_GC_REFCOUNT => pack('V', 11)]);

        $fn = $ns . '\\zendrefcounted_gc_refcount';
        $this->assertSame(11, $fn($buf, 0));
    }

    #[DataProvider('versionProvider')]
    public function testZendRefcountedGcTypeInfo(string $version): void
    {
        $this->requireReader($version, 'ZendRefcountedReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendRefcountedLayout';

        $buf = $this->buildBuffer(256, [$layout::OFFSET_GC_TYPE_INFO => pack('V', 0x0205)]);

        $fn = $ns . '\\zendrefcounted_gc_type_info';
        $this->assertSame(0x0205, $fn($buf, 0));
    }

    // ----------------------------------------------------------------
    // Layout SIZE constants
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testLayoutSizeConstants(string $version): void
    {
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";

        $layouts = [
            'ZvalLayout',
            'BucketLayout',
            'ZendArrayLayout',
            'ZendStringLayout',
            'ZendObjectLayout',
            'ZendClassEntryLayout',
            'ZendRefcountedLayout',
        ];

        foreach ($layouts as $layoutName) {
            $class = $ns . '\\' . $layoutName;
            $this->assertGreaterThan(0, $class::SIZE, "{$version}\\{$layoutName}::SIZE must be > 0");
        }
    }

    // ----------------------------------------------------------------
    // Non-zero offset: verify $off parameter works
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testNonZeroOffset(string $version): void
    {
        $this->requireReader($version, 'ZvalReader.php');
        $this->requireReader($version, 'BucketReader.php');
        $this->requireReader($version, 'ZendArrayReader.php');
        $this->requireReader($version, 'ZendStringReader.php');
        $this->requireReader($version, 'ZendObjectReader.php');
        $this->requireReader($version, 'ZendClassEntryReader.php');
        $this->requireReader($version, 'ZendRefcountedReader.php');

        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $off = 64;

        // Zval: write type=7 at (off + OFFSET_U1_V_TYPE)
        $zvalLayout = $ns . '\\ZvalLayout';
        $buf = $this->buildBuffer(256);
        $buf[$off + $zvalLayout::OFFSET_U1_V_TYPE] = chr(7);
        $fn = $ns . '\\zval_u1_v_type';
        $this->assertSame(7, $fn($buf, $off), 'zval_u1_v_type with offset=64');

        // Bucket: write key pointer at (off + OFFSET_KEY)
        $bucketLayout = $ns . '\\BucketLayout';
        $ptr = 0xAA00BB00CC00;
        $buf = $this->buildBuffer(256, [$off + $bucketLayout::OFFSET_KEY => pack('P', $ptr)]);
        $fn = $ns . '\\bucket_key';
        $this->assertSame($ptr, $fn($buf, $off), 'bucket_key with offset=64');

        // ZendArray: write nNumUsed at (off + OFFSET_N_NUM_USED)
        $arrayLayout = $ns . '\\ZendArrayLayout';
        $buf = $this->buildBuffer(256, [$off + $arrayLayout::OFFSET_N_NUM_USED => pack('V', 17)]);
        $fn = $ns . '\\zendarray_n_num_used';
        $this->assertSame(17, $fn($buf, $off), 'zendarray_n_num_used with offset=64');

        // ZendString: write len at (off + OFFSET_LEN)
        $stringLayout = $ns . '\\ZendStringLayout';
        $buf = $this->buildBuffer(256, [$off + $stringLayout::OFFSET_LEN => pack('P', 99)]);
        $fn = $ns . '\\zendstring_len';
        $this->assertSame(99, $fn($buf, $off), 'zendstring_len with offset=64');

        // ZendObject: write handle at (off + OFFSET_HANDLE)
        $objectLayout = $ns . '\\ZendObjectLayout';
        $buf = $this->buildBuffer(256, [$off + $objectLayout::OFFSET_HANDLE => pack('V', 55)]);
        $fn = $ns . '\\zendobject_handle';
        $this->assertSame(55, $fn($buf, $off), 'zendobject_handle with offset=64');

        // ZendClassEntry: write default_properties_count at (off + OFFSET_DEFAULT_PROPERTIES_COUNT)
        $ceLayout = $ns . '\\ZendClassEntryLayout';
        $buf = $this->buildBuffer(512, [$off + $ceLayout::OFFSET_DEFAULT_PROPERTIES_COUNT => pack('l', 3)]);
        $fn = $ns . '\\zendclassentry_default_properties_count';
        $this->assertSame(3, $fn($buf, $off), 'zendclassentry_default_properties_count with offset=64');

        // ZendRefcounted: write refcount at (off + OFFSET_GC_REFCOUNT)
        $rcLayout = $ns . '\\ZendRefcountedLayout';
        $buf = $this->buildBuffer(256, [$off + $rcLayout::OFFSET_GC_REFCOUNT => pack('V', 4)]);
        $fn = $ns . '\\zendrefcounted_gc_refcount';
        $this->assertSame(4, $fn($buf, $off), 'zendrefcounted_gc_refcount with offset=64');
    }

    // ----------------------------------------------------------------
    // Edge case: negative default_properties_count (signed int)
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testZendClassEntryNegativeDefaultPropertiesCount(string $version): void
    {
        $this->requireReader($version, 'ZendClassEntryReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZendClassEntryLayout';

        $buf = $this->buildBuffer(512, [$layout::OFFSET_DEFAULT_PROPERTIES_COUNT => pack('l', -1)]);

        $fn = $ns . '\\zendclassentry_default_properties_count';
        $this->assertSame(-1, $fn($buf, 0));
    }

    // ----------------------------------------------------------------
    // Edge case: maximum single-byte value (0xFF)
    // ----------------------------------------------------------------

    #[DataProvider('versionProvider')]
    public function testSingleByteMaxValue(string $version): void
    {
        $this->requireReader($version, 'ZvalReader.php');
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
        $layout = $ns . '\\ZvalLayout';

        $buf = $this->buildBuffer(256);
        $buf[$layout::OFFSET_U1_V_TYPE] = chr(0xFF);

        $fn = $ns . '\\zval_u1_v_type';
        $this->assertSame(0xFF, $fn($buf, 0));
    }
}
