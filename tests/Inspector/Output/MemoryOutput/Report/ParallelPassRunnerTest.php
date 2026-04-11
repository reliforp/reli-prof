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

namespace Reli\Inspector\Output\MemoryOutput\Report;

use Reli\BaseTestCase;

class ParallelPassRunnerTest extends BaseTestCase
{
    public function testWorkerCountZeroFallsBackToSequential(): void
    {
        $runner = new ParallelPassRunner(0);
        $findings = $runner->run([
            'a' => fn (): array => [$this->makeFinding('a')],
            'b' => fn (): array => [$this->makeFinding('b')],
        ]);
        $this->assertCount(2, $findings);
        $kinds = array_map(fn (Finding $f): string => $f->kind, $findings);
        $this->assertSame(['a', 'b'], $kinds);
    }

    public function testWorkerCountOneFallsBackToSequential(): void
    {
        $runner = new ParallelPassRunner(1);
        $findings = $runner->run([
            'a' => fn (): array => [$this->makeFinding('a'), $this->makeFinding('a2')],
            'b' => fn (): array => [$this->makeFinding('b')],
        ]);
        $this->assertCount(3, $findings);
    }

    public function testSequentialReturnsEmptyForEmptyFactories(): void
    {
        $runner = new ParallelPassRunner(0);
        $this->assertSame([], $runner->run([]));
    }

    public function testSinglePassFactoryFallsBackToSequentialEvenInParallelMode(): void
    {
        // Single-pass case takes the early-return branch (count <= 1).
        $runner = new ParallelPassRunner(4);
        $findings = $runner->run([
            'only' => fn (): array => [$this->makeFinding('only')],
        ]);
        $this->assertCount(1, $findings);
        $this->assertSame('only', $findings[0]->kind);
    }

    public function testSequentialPropagatesEmptyFactoryOutputs(): void
    {
        $runner = new ParallelPassRunner(0);
        $findings = $runner->run([
            'empty' => fn (): array => [],
            'one'   => fn (): array => [$this->makeFinding('one')],
            'empty2' => fn (): array => [],
        ]);
        $this->assertCount(1, $findings);
        $this->assertSame('one', $findings[0]->kind);
    }

    public function testParallelRunWithMultipleWorkers(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork unavailable');
        }
        $runner = new ParallelPassRunner(2);
        $findings = $runner->run([
            'a' => fn (): array => [$this->makeFinding('a')],
            'b' => fn (): array => [$this->makeFinding('b')],
            'c' => fn (): array => [$this->makeFinding('c'), $this->makeFinding('c2')],
        ]);
        $this->assertCount(4, $findings);
        $kinds = array_map(fn (Finding $f): string => $f->kind, $findings);
        sort($kinds);
        $this->assertSame(['a', 'b', 'c', 'c2'], $kinds);
    }

    public function testParallelRunSurvivesAFailingWorker(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork unavailable');
        }
        $runner = new ParallelPassRunner(2);
        // Suppress STDERR noise from the failing worker.
        $stderr_redirect = fopen('/dev/null', 'w');
        $orig_stderr = null;
        if ($stderr_redirect !== false) {
            $orig_stderr = STDERR;
        }
        $findings = $runner->run([
            'good' => fn (): array => [$this->makeFinding('good')],
            'bad'  => fn (): array => throw new \RuntimeException('boom'),
            'good2' => fn (): array => [$this->makeFinding('good2')],
        ]);
        if ($stderr_redirect !== false) {
            fclose($stderr_redirect);
        }
        // The two good workers must still be reaped, the bad one drops out.
        $kinds = array_map(fn (Finding $f): string => $f->kind, $findings);
        sort($kinds);
        $this->assertSame(['good', 'good2'], $kinds);
    }

    private function makeFinding(string $kind): Finding
    {
        return new Finding(
            kind: $kind,
            severity: FindingSeverity::Info,
            confidence: FindingConfidence::High,
            summary: 'test ' . $kind,
        );
    }
}
