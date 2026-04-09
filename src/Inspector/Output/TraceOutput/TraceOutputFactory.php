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

namespace Reli\Inspector\Output\TraceOutput;

use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Inspector\Output\OutputChannel\StreamOutputChannel;
use Reli\Inspector\Output\TraceFormatter\Templated\TraceFormatterFactory;
use Reli\Inspector\Settings\OutputSettings\OutputSettings;
use Reli\Inspector\Settings\TraceLoopSettings\TraceLoopSettings;
use Symfony\Component\Console\Output\OutputInterface;

final class TraceOutputFactory
{
    /** @param resource|null $default_stream stream to use when no output_path (default: STDOUT) */
    public function __construct(
        private TraceFormatterFactory $trace_formatter_factory,
        private $default_stream = null,
    ) {
    }

    public function fromSettingsAndConsoleOutput(
        OutputInterface $output,
        OutputSettings $output_settings,
        ?TraceLoopSettings $loop_settings = null,
    ): TraceOutput {
        $stream = $this->resolveStream($output_settings);

        if ($output_settings->isBinaryTrace() || $output_settings->isBinaryTraceBundled()) {
            $sampling_period_us = $this->deriveSamplingPeriodUs($loop_settings);
            $writer = new BinaryTraceWriter(
                $stream,
                $sampling_period_us,
                has_timestamps: $output_settings->hasRbtTimestamps(),
            );
            return new BinaryTraceOutput($writer);
        }

        // Direct stream I/O bypasses Symfony Console's Unicode
        // normalization overhead (normalizer_is_normalized, grapheme_strlen)
        return new FormattedTraceOutput(
            new StreamOutputChannel($stream),
            $this->trace_formatter_factory->createFromSettings(
                $output_settings
            )
        );
    }

    private function deriveSamplingPeriodUs(?TraceLoopSettings $loop_settings): int
    {
        if ($loop_settings !== null) {
            $us = (int)($loop_settings->sleep_nano_seconds / 1000);
            if ($us > 0) {
                return $us;
            }
        }
        return 10000; // fallback: 10ms
    }

    /**
     * @return resource
     */
    private function resolveStream(OutputSettings $output_settings)
    {
        if ($output_settings->output_path !== null) {
            $mode = $output_settings->isBinaryTrace() || $output_settings->isBinaryTraceBundled()
                ? 'wb'
                : 'w';
            $stream = fopen($output_settings->output_path, $mode, false);
            if ($stream === false) {
                throw new \RuntimeException("Failed to open output file: {$output_settings->output_path}");
            }
            return $stream;
        }
        return $this->default_stream ?? \STDOUT;
    }
}
