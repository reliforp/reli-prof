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
use Reli\Converter\BinaryTrace\FoldedStacksFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class BinaryTraceFoldedCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('converter:binary-trace-to-folded')
            ->setDescription('convert binary trace format to folded stacks (for flamegraph)')
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $reader = new BinaryTraceReader();
        $formatter = new FoldedStacksFormatter();

        $output->write($formatter->format($reader->read(STDIN)));

        return 0;
    }
}
