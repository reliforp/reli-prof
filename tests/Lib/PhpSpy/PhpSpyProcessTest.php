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

namespace Reli\Lib\PhpSpy;

use Reli\BaseTestCase;
use Reli\Inspector\Settings\PhpSpySettings\PhpSpySettings;

class PhpSpyProcessTest extends BaseTestCase
{
    private function createProcess(): PhpSpyProcess
    {
        return new PhpSpyProcess(new PhpSpyFinder());
    }

    public function testBuildArgsBasic(): void
    {
        $process = $this->createProcess();
        $settings = new PhpSpySettings();

        $args = $process->buildArgs(1234, 0x7f000000, 0x7f000100, 128, $settings);

        $this->assertContains('-p', $args);
        $this->assertContains('1234', $args);
        $this->assertContains('-x', $args);
        $this->assertContains('7f000000', $args);
        $this->assertContains('-a', $args);
        $this->assertContains('7f000100', $args);
        $this->assertContains('-n', $args);
        $this->assertContains('128', $args);
        $this->assertNotContains('-s', $args);
        $this->assertNotContains('-b', $args);
        $this->assertNotContains('-H', $args);
    }

    public function testBuildArgsWithCustomSettings(): void
    {
        $process = $this->createProcess();
        $settings = new PhpSpySettings(
            sleep_ns: 5000000,
            buffer_size: 8192,
            rate_hz: 50,
        );

        $args = $process->buildArgs(999, 0xABCDEF, 0x123456, 64, $settings);

        $this->assertContains('-s', $args);
        $this->assertContains('5000000', $args);
        $this->assertContains('-b', $args);
        $this->assertContains('8192', $args);
        $this->assertContains('-H', $args);
        $this->assertContains('50', $args);
    }

    public function testBuildArgsDepthUnlimited(): void
    {
        $process = $this->createProcess();
        $settings = new PhpSpySettings();

        $args = $process->buildArgs(1, 0x1000, 0x2000, PHP_INT_MAX, $settings);

        $this->assertContains('-1', $args);
        $this->assertNotContains((string)PHP_INT_MAX, $args);
    }

    public function testBuildArgsEgAddressFormat(): void
    {
        $process = $this->createProcess();
        $settings = new PhpSpySettings();

        $args = $process->buildArgs(1, 0x564102620bc0, 0x564102620600, 10, $settings);

        $this->assertContains('564102620bc0', $args);
        $this->assertContains('564102620600', $args);
        $this->assertNotContains('0x564102620bc0', $args);
    }

    public function testIsRunningReturnsFalseBeforeStart(): void
    {
        $process = $this->createProcess();
        $this->assertFalse($process->isRunning());
    }

    public function testPassthroughReturnsFalseBeforeStart(): void
    {
        $process = $this->createProcess();
        $this->assertFalse($process->passthrough(STDOUT));
    }

    public function testGetStderrContentsBeforeStart(): void
    {
        $process = $this->createProcess();
        $this->assertSame('', $process->getStderrContents());
    }

    public function testStopBeforeStartDoesNotThrow(): void
    {
        $process = $this->createProcess();
        $process->stop();
        $this->assertFalse($process->isRunning());
    }
}
