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

use Reli\Inspector\Output\OutputChannel\ConsoleOutputChannel;
use Reli\Inspector\Output\OutputChannel\StreamOutputChannel;
use Reli\Inspector\Output\TraceFormatter\Templated\TraceFormatterFactory;
use Reli\Inspector\Settings\OutputSettings\OutputSettings;
use Symfony\Component\Console\Output\OutputInterface;

final class TraceOutputFactory
{
    public function __construct(
        private TraceFormatterFactory $trace_formatter_factory,
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
            // Direct stream I/O bypasses Symfony Console's Unicode
            // normalization overhead (normalizer_is_normalized, grapheme_strlen)
            $output_channel = new StreamOutputChannel($stream);
        } else {
            $output_channel = new ConsoleOutputChannel($output);
        }
        return new FormattedTraceOutput(
            $output_channel,
            $this->trace_formatter_factory->createFromSettings(
                $output_settings
            )
        );
    }
}
