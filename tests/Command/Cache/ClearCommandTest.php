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

namespace Reli\Command\Cache;

use PHPUnit\Framework\TestCase;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\Elf\Process\BinaryFingerprint;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ClearCommandTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/reli-test-cmd-' . getmypid() . '-' . mt_rand();
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
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testClearCommandRemovesCacheEntries(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $cache->set(new BinaryFingerprint('fp1'), 'ns', ['data' => 1]);
        $cache->set(new BinaryFingerprint('fp2'), 'ns', ['data' => 2]);

        $command = new ClearCommand($cache);
        $output = new BufferedOutput();
        $result = $command->run(new ArrayInput([]), $output);

        $this->assertSame(0, $result);
        $this->assertStringContainsString('cleared 2 cached entries', $output->fetch());
    }

    public function testClearCommandWithEmptyCache(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $command = new ClearCommand($cache);
        $output = new BufferedOutput();
        $result = $command->run(new ArrayInput([]), $output);

        $this->assertSame(0, $result);
        $this->assertStringContainsString('cleared 0 cached entries', $output->fetch());
    }

    public function testClearCommandActuallyDeletesFiles(): void
    {
        $cache = new BinaryAnalysisCache($this->tmpDir);
        $fp = new BinaryFingerprint('fp1');
        $cache->set($fp, 'ns', ['data' => 1]);
        $this->assertNotNull($cache->get($fp, 'ns'));

        $command = new ClearCommand($cache);
        $command->run(new ArrayInput([]), new BufferedOutput());

        $cache2 = new BinaryAnalysisCache($this->tmpDir);
        $this->assertNull($cache2->get($fp, 'ns'));
    }
}
