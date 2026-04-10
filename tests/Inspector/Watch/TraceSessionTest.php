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

namespace Reli\Inspector\Watch;

use PHPUnit\Framework\TestCase;

class TraceSessionTest extends TestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir() . '/reli-test-trace-session-' . getmypid();
        @mkdir($this->tmpdir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up any generated files
        $files = glob($this->tmpdir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tmpdir);
    }

    public function testInitiallyInactive(): void
    {
        $session = new TraceSession($this->tmpdir);
        $this->assertFalse($session->isActive());
        $this->assertNull($session->getCurrentPath());
    }

    public function testStartActivatesSession(): void
    {
        $session = new TraceSession($this->tmpdir);
        $session->start(1234, 1700000000.123);

        $this->assertTrue($session->isActive());
        $this->assertNotNull($session->getCurrentPath());
        $this->assertStringContainsString('watch-trace-1234-', $session->getCurrentPath());
        $this->assertStringEndsWith('.rbt', $session->getCurrentPath());

        $session->stop();
    }

    public function testStopDeactivatesSession(): void
    {
        $session = new TraceSession($this->tmpdir);
        $session->start(1234, 1700000000.0);
        $session->stop();

        $this->assertFalse($session->isActive());
    }

    public function testDoubleStartIsNoop(): void
    {
        $session = new TraceSession($this->tmpdir);
        $session->start(1234, 1700000000.0);
        $path1 = $session->getCurrentPath();

        $session->start(5678, 1700000001.0);
        $path2 = $session->getCurrentPath();

        // Path should not change — second start is no-op
        $this->assertSame($path1, $path2);

        $session->stop();
    }

    public function testDoubleStopIsNoop(): void
    {
        $session = new TraceSession($this->tmpdir);
        $session->start(1234, 1700000000.0);
        $session->stop();
        // Should not throw
        $session->stop();
        $this->assertFalse($session->isActive());
    }

    public function testStopWithoutStartIsNoop(): void
    {
        $session = new TraceSession($this->tmpdir);
        // Should not throw
        $session->stop();
        $this->assertFalse($session->isActive());
    }

    public function testOutputFileIsCreated(): void
    {
        $session = new TraceSession($this->tmpdir);
        $session->start(1234, 1700000000.0);
        $path = $session->getCurrentPath();
        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $session->stop();
    }
}
