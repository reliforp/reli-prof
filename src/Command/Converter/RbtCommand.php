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

use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Converter\TraceInputReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RbtCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('converter:rbt')
            ->setDescription('convert traces to rbt binary format (auto-detects rbt or phpspy input)')
            ->addOption(
                'sampling-period',
                null,
                InputOption::VALUE_REQUIRED,
                'Sampling period in microseconds',
                '10000',
            )
            ->addOption(
                'rbt-timestamps',
                null,
                InputOption::VALUE_REQUIRED,
                'Timestamp mode: none or delta',
                'none',
            )
            ->addOption(
                'compress',
                null,
                InputOption::VALUE_NONE,
                'Gzip-compress the output (.rbt.gz)',
            )
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $sampling_period = (int)$input->getOption('sampling-period');
        $has_timestamps = $input->getOption('rbt-timestamps') === 'delta';
        $compress = (bool)$input->getOption('compress');

        $reader = new TraceInputReader();

        if ($compress) {
            // Write to temp (spills to disk if large), then gzip to stdout
            $buffer = fopen('php://temp', 'r+');
            assert($buffer !== false);
            $writer = new BinaryTraceWriter($buffer, $sampling_period, $has_timestamps);
        } else {
            $buffer = null;
            $writer = new BinaryTraceWriter(STDOUT, $sampling_period, $has_timestamps);
        }

        $writer->writeHeader();
        foreach ($reader->read(STDIN) as $trace) {
            $writer->writeTrace($trace);
        }
        $writer->writeCheckpoint();
        $writer->writeSegmentEnd();

        if ($buffer !== null) {
            rewind($buffer);
            $raw = stream_get_contents($buffer);
            fclose($buffer);
            assert($raw !== false);
            $compressed = gzencode($raw);
            if ($compressed === false) {
                throw new \RuntimeException('Failed to gzip-compress rbt output');
            }
            fwrite(STDOUT, $compressed);
        }

        return 0;
    }
}
