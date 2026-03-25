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
        if ($output_settings->output_path !== null) {
            $stream = fopen($output_settings->output_path, 'w', false);
            if ($stream === false) {
                throw new \RuntimeException("Failed to open output file: {$output_settings->output_path}");
            }
        } else {
            $stream = $this->default_stream ?? \STDOUT;
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
}
