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

namespace Reli\Command\Converter;

use Reli\Converter\BinaryTrace\BinaryTraceReader;
use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class BinaryTraceRecoverCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('converter:binary-trace-recover')
            ->setDescription('recover samples from a corrupted or truncated binary trace file')
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: rbt (re-encoded binary trace) or phpspy (text)',
                'rbt',
            )
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $format */
        $format = $input->getOption('format');
        $reader = new BinaryTraceReader();

        $count = 0;
        if ($format === 'phpspy') {
            foreach ($reader->readWithRecovery(STDIN) as $sample) {
                foreach ($sample->trace->call_frames as $depth => $frame) {
                    $output->writeln(
                        $depth . ' '
                        . $frame->function_name . ' '
                        . $frame->file_name . ':' . $frame->lineno
                    );
                }
                $output->writeln('');
                $count++;
            }
        } else {
            // Defer writer creation until the first sample so the reader has
            // already parsed at least one header and getSamplingPeriodUs()
            // returns the actual value from the input file.
            $writer = null;
            foreach ($reader->readWithRecovery(STDIN) as $sample) {
                if ($writer === null) {
                    $writer = new BinaryTraceWriter(
                        STDOUT,
                        $reader->getSamplingPeriodUs() ?: 10000,
                        has_timestamps: true,
                    );
                    $writer->writeHeader();
                }
                $writer->writeTrace($sample->trace, $sample->timestamp_delta_us ?? 0);
                $count++;
            }
            if ($writer !== null) {
                $writer->writeCheckpoint();
                $writer->writeSegmentEnd();
            }
        }

        if ($output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln("Recovered {$count} samples");
        }

        return 0;
    }
}
