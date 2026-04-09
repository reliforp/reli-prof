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
    ): TraceOutput {
        $stream = $this->resolveStream($output_settings);

        if ($output_settings->isBinaryTrace() || $output_settings->isBinaryTraceBundled()) {
            $sampling_period_us = 10000; // default; matches TraceLoopSettings default of 10ms
            $writer = new BinaryTraceWriter($stream, $sampling_period_us, has_timestamps: true);
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

    /**
     * @return resource
     */
    private function resolveStream(OutputSettings $output_settings)
    {
        if ($output_settings->output_path !== null) {
            $stream = fopen($output_settings->output_path, 'w', false);
            if ($stream === false) {
                throw new \RuntimeException("Failed to open output file: {$output_settings->output_path}");
            }
            return $stream;
        }
        return $this->default_stream ?? \STDOUT;
    }
}
