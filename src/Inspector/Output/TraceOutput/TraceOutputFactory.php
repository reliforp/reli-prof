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
use Reli\Inspector\Output\TraceFormatter\Templated\TraceFormatterFactory;
use Reli\Inspector\Settings\OutputSettings\OutputSettings;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

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
        if (!is_null($output_settings->output_path)) {
            $stream = fopen($output_settings->output_path, 'w', false);
            if ($stream === false) {
                throw new \RuntimeException("Failed to open output file: {$output_settings->output_path}");
            }
            $output = new StreamOutput($stream);
        }
        return new FormattedTraceOutput(
            new ConsoleOutputChannel($output),
            $this->trace_formatter_factory->createFromSettings(
                $output_settings
            )
        );
    }
}
