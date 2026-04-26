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

namespace Reli\Lib\JitAtomic;

use Reli\BaseTestCase;

class SharedAtomicAddressMapTest extends BaseTestCase
{
    protected function setUp(): void
    {
        if (!AtomicCodeRegion::isSupported()) {
            $this->markTestSkipped('SharedAtomicAddressMap requires Linux x86_64 + FFI');
        }
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required');
        }
    }

    public function testBasicSingleProcess(): void
    {
        $map = SharedAtomicAddressMap::createOwning(64);
        $this->assertFalse($map->has(0xdead));
        $this->assertNull($map->get(0xdead));

        $prior = $map->setIfAbsent(0xdead, 42);
        $this->assertNull($prior);
        $this->assertTrue($map->has(0xdead));
        $this->assertSame(42, $map->get(0xdead));

        // setIfAbsent with same key returns existing
        $this->assertSame(42, $map->setIfAbsent(0xdead, 999));
        $this->assertSame(42, $map->get(0xdead));
    }

    public function testValueZeroPreserved(): void
    {
        $map = SharedAtomicAddressMap::createOwning(16);
        $this->assertNull($map->setIfAbsent(0xbeef, 0));
        $this->assertTrue($map->has(0xbeef));
        $this->assertSame(0, $map->get(0xbeef));
    }

    public function testCrossProcessSharing(): void
    {
        // Parent allocates the shared map and writes one key.
        // Each child attaches via the descriptor, sees the parent's key,
        // and writes its own. Parent reads back all writes after waitpid.
        $host = SharedAtomicAddressMap::createOwning(1024);
        $desc = $host->descriptor();
        $host->set(0xc0de, 555);

        $N = 4;
        $perChild = 50;
        $logs = [];
        $pids = [];
        for ($t = 0; $t < $N; $t++) {
            $log = tempnam(sys_get_temp_dir(), 'samtest_');
            $logs[$t] = $log;
            $pid = pcntl_fork();
            if ($pid === 0) {
                $map = SharedAtomicAddressMap::attach($desc);
                $sees = $map->get(0xc0de);
                $news = 0;
                for ($i = 0; $i < $perChild; $i++) {
                    $k = 1 + $t * 100000 + $i;
                    if ($map->setIfAbsent($k, $t * 100000 + $i) === null) {
                        $news++;
                    }
                }
                file_put_contents($log, "$sees|$news");
                exit(0);
            }
            $pids[$t] = $pid;
        }
        foreach ($pids as $p) {
            pcntl_waitpid($p, $status);
        }

        // All children should have observed parent's pre-fork insert.
        // Parent should observe all children's writes.
        for ($t = 0; $t < $N; $t++) {
            $contents = file_get_contents($logs[$t]);
            @unlink($logs[$t]);
            $this->assertSame("555|$perChild", $contents, "child $t observation");
        }
        $observed = 0;
        for ($t = 0; $t < $N; $t++) {
            for ($i = 0; $i < $perChild; $i++) {
                $k = 1 + $t * 100000 + $i;
                if ($host->get($k) === $t * 100000 + $i) {
                    $observed++;
                }
            }
        }
        $this->assertSame($N * $perChild, $observed, 'parent must read all child writes');
    }
}
