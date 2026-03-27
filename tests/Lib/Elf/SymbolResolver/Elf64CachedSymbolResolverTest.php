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

namespace Reli\Lib\Elf\SymbolResolver;

use Mockery;
use Reli\BaseTestCase;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprint;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;
use Reli\Lib\Integer\UInt64;

class Elf64CachedSymbolResolverTest extends BaseTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/reli-test-resolver-' . getmypid() . '-' . mt_rand();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeEntry(int $value = 100, int $size = 8, int $info = 1): Elf64SymbolTableEntry
    {
        return new Elf64SymbolTableEntry(
            1,
            $info,
            0,
            1,
            UInt64::fromInt($value),
            UInt64::fromInt($size),
        );
    }

    public function testResolveUsesInMemoryCacheFirst(): void
    {
        $inner = Mockery::mock(Elf64SymbolResolver::class);
        $inner->expects()->resolve('test_sym')->once()->andReturns($this->makeEntry());
        $inner->allows()->getBaseAddress()->andReturns(new UInt64(0, 0));

        $resolver = new Elf64CachedSymbolResolver($inner, new Elf64SymbolCache());

        $first = $resolver->resolve('test_sym');
        $second = $resolver->resolve('test_sym');
        $this->assertSame($first, $second);
    }

    public function testResolvePopulatesFileCache(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fp = new BinaryFingerprint('test_fp');
        $entry = $this->makeEntry(42, 16);

        $inner = Mockery::mock(Elf64SymbolResolver::class);
        $inner->expects()->resolve('my_symbol')->andReturns($entry);
        $inner->allows()->getBaseAddress()->andReturns(new UInt64(0, 0));

        $resolver = new Elf64CachedSymbolResolver($inner, new Elf64SymbolCache(), $cache, $fp);
        $resolver->resolve('my_symbol');

        $cached = $cache->get($fp, 'elf_symbols');
        $this->assertNotNull($cached);
        $this->assertArrayHasKey('my_symbol', $cached);
        $this->assertSame(42, $cached['my_symbol']['st_value_lo']);
        $this->assertSame(16, $cached['my_symbol']['st_size_lo']);
    }

    public function testResolveReadsFromFileCacheWithoutInnerCall(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fp = new BinaryFingerprint('test_fp');
        $entry = $this->makeEntry(99, 4);

        // First resolver populates file cache
        $inner1 = Mockery::mock(Elf64SymbolResolver::class);
        $inner1->expects()->resolve('cached_sym')->andReturns($entry);
        $inner1->allows()->getBaseAddress()->andReturns(new UInt64(0, 0));
        $r1 = new Elf64CachedSymbolResolver($inner1, new Elf64SymbolCache(), $cache, $fp);
        $r1->resolve('cached_sym');

        // Second resolver should NOT call inner->resolve (file cache hit)
        $inner2 = Mockery::mock(Elf64SymbolResolver::class);
        $inner2->shouldNotReceive('resolve');
        $inner2->allows()->getBaseAddress()->andReturns(new UInt64(0, 0));
        $r2 = new Elf64CachedSymbolResolver($inner2, new Elf64SymbolCache(), $cache, $fp);
        $result = $r2->resolve('cached_sym');

        $this->assertSame(99, $result->st_value->toInt());
        $this->assertSame(4, $result->st_size->toInt());
    }

    public function testResolveHandlesUndefinedSymbol(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fp = new BinaryFingerprint('test_fp');
        $undefined = new Elf64SymbolTableEntry(0, 0, 0, 0, new UInt64(0, 0), new UInt64(0, 0));

        $inner = Mockery::mock(Elf64SymbolResolver::class);
        $inner->expects()->resolve('nonexistent')->andReturns($undefined);
        $inner->allows()->getBaseAddress()->andReturns(new UInt64(0, 0));

        $r1 = new Elf64CachedSymbolResolver($inner, new Elf64SymbolCache(), $cache, $fp);
        $result = $r1->resolve('nonexistent');
        $this->assertTrue($result->isUndefined());

        // Second resolver reads undefined from file cache
        $inner2 = Mockery::mock(Elf64SymbolResolver::class);
        $inner2->shouldNotReceive('resolve');
        $inner2->allows()->getBaseAddress()->andReturns(new UInt64(0, 0));
        $r2 = new Elf64CachedSymbolResolver($inner2, new Elf64SymbolCache(), $cache, $fp);
        $result2 = $r2->resolve('nonexistent');
        $this->assertTrue($result2->isUndefined());
    }

    public function testResolveTlsSymbol(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fp = new BinaryFingerprint('test_fp');
        $tls_entry = new Elf64SymbolTableEntry(
            1,
            Elf64SymbolTableEntry::createInfo(Elf64SymbolTableEntry::STB_GLOBAL, Elf64SymbolTableEntry::STT_TLS),
            0,
            1,
            UInt64::fromInt(64),
            UInt64::fromInt(8),
        );

        $inner = Mockery::mock(Elf64SymbolResolver::class);
        $inner->expects()->resolve('tls_var')->andReturns($tls_entry);
        $inner->allows()->getBaseAddress()->andReturns(new UInt64(0, 0));
        $r1 = new Elf64CachedSymbolResolver($inner, new Elf64SymbolCache(), $cache, $fp);
        $r1->resolve('tls_var');

        // Verify TLS type is preserved through file cache
        $inner2 = Mockery::mock(Elf64SymbolResolver::class);
        $inner2->shouldNotReceive('resolve');
        $inner2->allows()->getBaseAddress()->andReturns(new UInt64(0, 0));
        $r2 = new Elf64CachedSymbolResolver($inner2, new Elf64SymbolCache(), $cache, $fp);
        $result = $r2->resolve('tls_var');
        $this->assertTrue($result->isTls());
        $this->assertSame(64, $result->st_value->toInt());
    }

    public function testGetBaseAddressCachesInFile(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fp = new BinaryFingerprint('test_fp');

        $inner1 = Mockery::mock(Elf64SymbolResolver::class);
        $inner1->expects()->getBaseAddress()->once()->andReturns(new UInt64(0, 0x400000));
        $r1 = new Elf64CachedSymbolResolver($inner1, new Elf64SymbolCache(), $cache, $fp);
        $base1 = $r1->getBaseAddress();
        $this->assertSame(0x400000, $base1->toInt());

        // Second resolver reads from file cache
        $inner2 = Mockery::mock(Elf64SymbolResolver::class);
        $inner2->shouldNotReceive('getBaseAddress');
        $r2 = new Elf64CachedSymbolResolver($inner2, new Elf64SymbolCache(), $cache, $fp);
        $base2 = $r2->getBaseAddress();
        $this->assertSame(0x400000, $base2->toInt());
    }

    public function testGetDtDebugAddressCachesInFile(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fp = new BinaryFingerprint('test_fp');

        $inner1 = Mockery::mock(Elf64SymbolResolver::class);
        $inner1->expects()->getDtDebugAddress()->once()->andReturns(0x601000);
        $r1 = new Elf64CachedSymbolResolver($inner1, new Elf64SymbolCache(), $cache, $fp);
        $this->assertSame(0x601000, $r1->getDtDebugAddress());

        $inner2 = Mockery::mock(Elf64SymbolResolver::class);
        $inner2->shouldNotReceive('getDtDebugAddress');
        $r2 = new Elf64CachedSymbolResolver($inner2, new Elf64SymbolCache(), $cache, $fp);
        $this->assertSame(0x601000, $r2->getDtDebugAddress());
    }

    public function testGetDtDebugAddressCachesNullValue(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fp = new BinaryFingerprint('test_fp');

        $inner1 = Mockery::mock(Elf64SymbolResolver::class);
        $inner1->expects()->getDtDebugAddress()->once()->andReturns(null);
        $r1 = new Elf64CachedSymbolResolver($inner1, new Elf64SymbolCache(), $cache, $fp);
        $this->assertNull($r1->getDtDebugAddress());

        $inner2 = Mockery::mock(Elf64SymbolResolver::class);
        $inner2->shouldNotReceive('getDtDebugAddress');
        $r2 = new Elf64CachedSymbolResolver($inner2, new Elf64SymbolCache(), $cache, $fp);
        $this->assertNull($r2->getDtDebugAddress());
    }

    public function testMultipleSymbolsAccumulateInFileCache(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fp = new BinaryFingerprint('test_fp');

        $inner = Mockery::mock(Elf64SymbolResolver::class);
        $inner->expects()->resolve('sym_a')->andReturns($this->makeEntry(10));
        $inner->expects()->resolve('sym_b')->andReturns($this->makeEntry(20));
        $inner->allows()->getBaseAddress()->andReturns(new UInt64(0, 0));

        $r = new Elf64CachedSymbolResolver($inner, new Elf64SymbolCache(), $cache, $fp);
        $r->resolve('sym_a');
        $r->resolve('sym_b');

        $cached = $cache->get($fp, 'elf_symbols');
        $this->assertNotNull($cached);
        $this->assertArrayHasKey('sym_a', $cached);
        $this->assertArrayHasKey('sym_b', $cached);
    }

    public function testWithoutFileCacheFallsBackToInner(): void
    {
        $inner = Mockery::mock(Elf64SymbolResolver::class);
        $inner->expects()->resolve('sym')->andReturns($this->makeEntry(55));
        $inner->allows()->getBaseAddress()->andReturns(new UInt64(0, 0));

        // No BinaryAnalysisCache or BinaryFingerprint
        $r = new Elf64CachedSymbolResolver($inner, new Elf64SymbolCache());
        $result = $r->resolve('sym');
        $this->assertSame(55, $result->st_value->toInt());
    }

    public function testCorruptedFileCacheFallsBackToInner(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fp = new BinaryFingerprint('test_fp');

        // Write garbage to the symbols file
        $path = $this->tmpDir . '/' . $fp->getCacheKey() . '/elf_symbols.json';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, '{invalid json');

        $inner = Mockery::mock(Elf64SymbolResolver::class);
        $inner->expects()->resolve('sym')->andReturns($this->makeEntry(77));
        $inner->allows()->getBaseAddress()->andReturns(new UInt64(0, 0));

        $r = new Elf64CachedSymbolResolver($inner, new Elf64SymbolCache(), $cache, $fp);
        $result = $r->resolve('sym');
        $this->assertSame(77, $result->st_value->toInt());
    }

    public function testGetBaseAddressReturnsCachedValueOnRepeatCall(): void
    {
        $inner = Mockery::mock(Elf64SymbolResolver::class);
        $inner->expects()->getBaseAddress()->once()->andReturns(new UInt64(0, 0x1000));

        $r = new Elf64CachedSymbolResolver($inner, new Elf64SymbolCache());
        $this->assertSame(0x1000, $r->getBaseAddress()->toInt());
        $this->assertSame(0x1000, $r->getBaseAddress()->toInt()); // in-memory hit
    }
}
