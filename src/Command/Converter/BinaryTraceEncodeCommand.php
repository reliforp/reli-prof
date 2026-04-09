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
use Reli\Converter\PhpSpyCompatibleParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class BinaryTraceEncodeCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('converter:binary-trace-encode')
            ->setDescription('convert phpspy-compatible traces to binary trace format')
            ->addOption(
                'sampling-period',
                null,
                InputOption::VALUE_REQUIRED,
                'Sampling period in microseconds',
                '10000',
            )
            ->addOption(
                'checkpoint-interval',
                null,
                InputOption::VALUE_REQUIRED,
                'Number of samples between checkpoints',
                '1000',
            )
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $sampling_period = (int)$input->getOption('sampling-period');
        $checkpoint_interval = (int)$input->getOption('checkpoint-interval');

        $writer = new BinaryTraceWriter(STDOUT, $sampling_period);
        $writer->writeHeader();

        $parser = new PhpSpyCompatibleParser();
        $count = 0;
        foreach ($parser->parseFile(STDIN) as $trace) {
            $writer->writeTrace($trace);
            $count++;
            if ($count % $checkpoint_interval === 0) {
                $writer->writeCheckpoint();
            }
        }

        $writer->writeCheckpoint();
        $writer->writeSegmentEnd();

        return 0;
    }
}
