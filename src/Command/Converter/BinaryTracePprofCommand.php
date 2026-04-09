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
use Reli\Converter\BinaryTrace\PprofEncoder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class BinaryTracePprofCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('converter:binary-trace-to-pprof')
            ->setDescription('convert binary trace format to pprof protobuf')
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $reader = new BinaryTraceReader();
        $encoder = new PprofEncoder();

        // Streaming: PprofEncoder aggregates internally, no need to buffer all traces
        $pprof_data = $encoder->encode(
            $reader->read(STDIN),
            $reader->getSamplingPeriodUs() ?: 10000,
        );

        // pprof files are gzip-compressed
        $compressed = gzencode($pprof_data);
        if ($compressed === false) {
            throw new \RuntimeException('Failed to gzip-compress pprof data');
        }

        fwrite(STDOUT, $compressed);

        return 0;
    }
}
