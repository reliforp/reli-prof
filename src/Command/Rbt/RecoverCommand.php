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

namespace Reli\Command\Rbt;

use Reli\Converter\BinaryTrace\BinaryTraceReader;
use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Converter\StreamDecompressor;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RecoverCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('rbt:recover')
            ->setDescription('recover samples from a corrupted or truncated rbt file')
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
        $input_stream = StreamDecompressor::decompressIfNeeded(STDIN);

        $count = 0;
        if ($format === 'phpspy') {
            foreach ($reader->readWithRecovery($input_stream) as $sample) {
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
            $writer = null;
            foreach ($reader->readWithRecovery($input_stream) as $sample) {
                if ($writer === null) {
                    $writer = new BinaryTraceWriter(
                        STDOUT,
                        $reader->getSamplingPeriodUs() ?: 10000,
                        has_timestamps: true,
                    );
                    $writer->writeHeader();
                }
                $writer->writeTrace(
                    $sample->trace,
                    $sample->timestamp_delta_us ?? 0,
                );
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
