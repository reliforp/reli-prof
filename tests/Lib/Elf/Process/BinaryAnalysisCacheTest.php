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

namespace Reli\Lib\Elf\Process;

use PHPUnit\Framework\TestCase;

class BinaryAnalysisCacheTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/reli-test-cache-' . getmypid() . '-' . mt_rand();
    }

    protected function tearDown(): void
    {
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
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testGetReturnsNullOnCacheMiss(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fingerprint = new BinaryFingerprint('test_fingerprint');
        $this->assertNull($cache->get($fingerprint, 'test_namespace'));
    }

    public function testSetThenGetReturnsCachedData(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fingerprint = new BinaryFingerprint('test_fingerprint');
        $data = ['key' => 'value', 'number' => 42];
        $cache->set($fingerprint, 'test_namespace', $data);
        $this->assertSame($data, $cache->get($fingerprint, 'test_namespace'));
    }

    public function testGetReturnsNullOnCorruptedFile(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fingerprint = new BinaryFingerprint('test_fingerprint');
        $path = $this->tmpDir . '/' . $fingerprint->getCacheKey() . '/test_namespace.json';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'not valid json{{{');
        $this->assertNull($cache->get($fingerprint, 'test_namespace'));
    }

    public function testDifferentNamespacesAreIndependent(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fingerprint = new BinaryFingerprint('test_fingerprint');
        $cache->set($fingerprint, 'ns_a', ['a' => 1]);
        $cache->set($fingerprint, 'ns_b', ['b' => 2]);
        $this->assertSame(['a' => 1], $cache->get($fingerprint, 'ns_a'));
        $this->assertSame(['b' => 2], $cache->get($fingerprint, 'ns_b'));
    }

    public function testDifferentFingerprintsAreIndependent(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fp1 = new BinaryFingerprint('fingerprint_1');
        $fp2 = new BinaryFingerprint('fingerprint_2');
        $cache->set($fp1, 'ns', ['fp' => 1]);
        $cache->set($fp2, 'ns', ['fp' => 2]);
        $this->assertSame(['fp' => 1], $cache->get($fp1, 'ns'));
        $this->assertSame(['fp' => 2], $cache->get($fp2, 'ns'));
    }

    public function testGetReturnsNullForNonArrayJson(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fingerprint = new BinaryFingerprint('test_fingerprint');
        $path = $this->tmpDir . '/' . $fingerprint->getCacheKey() . '/test_namespace.json';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, '"just a string"');
        $this->assertNull($cache->get($fingerprint, 'test_namespace'));
    }

    public function testSetOverwritesExistingData(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fingerprint = new BinaryFingerprint('test_fingerprint');
        $cache->set($fingerprint, 'ns', ['v' => 1]);
        $cache->set($fingerprint, 'ns', ['v' => 2]);
        $this->assertSame(['v' => 2], $cache->get($fingerprint, 'ns'));
    }

    public function testCacheKeyIsDeterministic(): void
    {
        $fp1 = new BinaryFingerprint('same_value');
        $fp2 = new BinaryFingerprint('same_value');
        $this->assertSame($fp1->getCacheKey(), $fp2->getCacheKey());
    }

    public function testCacheKeyDiffersForDifferentFingerprints(): void
    {
        $fp1 = new BinaryFingerprint('value_a');
        $fp2 = new BinaryFingerprint('value_b');
        $this->assertNotSame($fp1->getCacheKey(), $fp2->getCacheKey());
    }
}
