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

use Reli\Converter\TraceInputReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PhpspyCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('converter:phpspy')
            ->setDescription('convert traces to phpspy-compatible text (auto-detects rbt or phpspy input)')
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $reader = new TraceInputReader();

        foreach ($reader->read(STDIN) as $trace) {
            foreach ($trace->call_frames as $depth => $frame) {
                $output->writeln(
                    $depth . ' '
                    . $frame->function_name . ' '
                    . $frame->file_name . ':' . $frame->lineno
                );
            }
            $output->writeln('');
        }

        return 0;
    }
}
