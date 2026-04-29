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

namespace Reli\Inspector\Settings\MemoryProfilerSettings;

use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;

class MemoryProfilerSettingsFromConsoleInputTest extends BaseTestCase
{
    /**
     * @return \Mockery\MockInterface&InputInterface
     */
    private function createBaseMock(): \Mockery\MockInterface
    {
        $input = Mockery::mock(InputInterface::class);
        $input->allows()->getOption('db-host')->andReturns('127.0.0.1');
        $input->allows()->getOption('db-port')->andReturns(null);
        $input->allows()->getOption('db-name')->andReturns(null);
        $input->allows()->getOption('db-user')->andReturns(null);
        $input->allows()->getOption('db-password')->andReturns(null);
        return $input;
    }

    public function testFromConsoleInput(): void
    {
        $input = $this->createBaseMock();
        $input->expects()->getOption('stop-process')->andReturns(true)->atLeast()->once();
        $input->expects()->getOption('pretty-print')->andReturns(false)->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-file')->andReturns('abc.php')->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-line')->andReturns(20)->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-max-depth')->andReturns(512)->atLeast()->once();
        $input->expects()->getOption('output-format')->andReturns('json')->atLeast()->once();
        $input->expects()->getOption('output')->andReturns(null)->atLeast()->once();

        $settings = (new MemoryProfilerSettingsFromConsoleInput())->createSettings($input);

        $this->assertTrue($settings->stop_process);
        $this->assertFalse($settings->pretty_print);
        $this->assertSame('abc.php', $settings->memory_exhaustion_error_details->file);
        $this->assertSame(20, $settings->memory_exhaustion_error_details->line);
        $this->assertSame(512, $settings->memory_exhaustion_error_details->max_challenge_depth);
        $this->assertSame('json', $settings->output_format);
        $this->assertNull($settings->output_path);
    }

    public function testFromConsoleInputDepthNotInteger(): void
    {
        $input = $this->createBaseMock();
        $input->expects()->getOption('stop-process')->andReturns(true)->zeroOrMoreTimes();
        $input->expects()->getOption('pretty-print')->andReturns(false)->zeroOrMoreTimes();
        $input->expects()->getOption('memory-limit-error-file')->andReturns('abc.php')->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-line')->andReturns(20)->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-max-depth')->andReturns('abc');
        $input->expects()->getOption('output-format')->andReturns('json')->zeroOrMoreTimes();
        $input->expects()->getOption('output')->andReturns(null)->zeroOrMoreTimes();
        $this->expectException(MemoryProfilerSettingsException::class);
        $this->expectExceptionCode(
            MemoryProfilerSettingsException::MEMORY_LIMIT_ERROR_MAX_DEPTH_IS_NOT_POSITIVE_INTEGER
        );
        (new MemoryProfilerSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputDepthNotPositive(): void
    {
        $input = $this->createBaseMock();
        $input->expects()->getOption('stop-process')->andReturns(true)->zeroOrMoreTimes();
        $input->expects()->getOption('pretty-print')->andReturns(false)->zeroOrMoreTimes();
        $input->expects()->getOption('memory-limit-error-file')->andReturns('abc.php')->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-line')->andReturns(20)->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-max-depth')->andReturns(-1);
        $input->expects()->getOption('output-format')->andReturns('json')->zeroOrMoreTimes();
        $input->expects()->getOption('output')->andReturns(null)->zeroOrMoreTimes();
        $this->expectException(MemoryProfilerSettingsException::class);
        $this->expectExceptionCode(
            MemoryProfilerSettingsException::MEMORY_LIMIT_ERROR_MAX_DEPTH_IS_NOT_POSITIVE_INTEGER
        );
        (new MemoryProfilerSettingsFromConsoleInput())->createSettings($input);
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string|null, 2: string}>
     */
    public static function formatDetectionCases(): iterable
    {
        yield 'no -f and no -o falls back to json' => [null, null, 'json'];
        yield '.rmem extension picks rmem' => [null, '/tmp/snap.rmem', 'rmem'];
        yield '.RMEM uppercase still picks rmem' => [null, '/tmp/SNAP.RMEM', 'rmem'];
        yield '.sqlite3 picks sqlite3' => [null, '/tmp/snap.sqlite3', 'sqlite3'];
        yield '.sqlite picks sqlite3' => [null, '/tmp/snap.sqlite', 'sqlite3'];
        yield '.db picks sqlite3' => [null, '/tmp/snap.db', 'sqlite3'];
        yield '.json extension does NOT auto-pick (falls back to json default)'
            => [null, '/tmp/snap.json', 'json'];
        yield 'unknown extension falls back to json' => [null, '/tmp/snap.bin', 'json'];
        yield 'explicit -f wins over .rmem extension' => ['json', '/tmp/snap.rmem', 'json'];
        yield 'explicit -f rmem' => ['rmem', '/tmp/snap.rmem', 'rmem'];
    }

    #[DataProvider('formatDetectionCases')]
    public function testFormatDetection(?string $format, ?string $path, string $expected): void
    {
        $input = $this->createBaseMock();
        $input->expects()->getOption('stop-process')->andReturns(true)->zeroOrMoreTimes();
        $input->expects()->getOption('pretty-print')->andReturns(false)->zeroOrMoreTimes();
        $input->expects()->getOption('memory-limit-error-file')->andReturns(null)->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-line')->andReturns(null)->zeroOrMoreTimes();
        $input->expects()->getOption('memory-limit-error-max-depth')->andReturns(512)->zeroOrMoreTimes();
        $input->expects()->getOption('output-format')->andReturns($format)->atLeast()->once();
        $input->expects()->getOption('output')->andReturns($path)->atLeast()->once();

        $settings = (new MemoryProfilerSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame($expected, $settings->output_format);
        $this->assertSame($path, $settings->output_path);
    }

    public function testFromConsoleInputLineNotInteger(): void
    {
        $input = $this->createBaseMock();
        $input->expects()->getOption('stop-process')->andReturns(true)->zeroOrMoreTimes();
        $input->expects()->getOption('pretty-print')->andReturns(false)->zeroOrMoreTimes();
        $input->expects()->getOption('memory-limit-error-file')->andReturns('abc.php')->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-line')->andReturns('abc')->atLeast()->once();
        $input->expects()->getOption('memory-limit-error-max-depth')->andReturns(512)->zeroOrMoreTimes();
        $input->expects()->getOption('output-format')->andReturns('json')->zeroOrMoreTimes();
        $input->expects()->getOption('output')->andReturns(null)->zeroOrMoreTimes();
        $this->expectException(MemoryProfilerSettingsException::class);
        $this->expectExceptionCode(
            MemoryProfilerSettingsException::MEMORY_LIMIT_ERROR_LINE_IS_NOT_INTEGER
        );
        (new MemoryProfilerSettingsFromConsoleInput())->createSettings($input);
    }
}
