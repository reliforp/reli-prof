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

use Reli\Converter\Callgrind\FunctionEntry;
use Reli\Converter\Callgrind\Profile;
use Reli\Converter\ParsedCallTrace;
use Reli\Converter\PhpSpyCompatibleParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CallgrindCommand extends Command
{
    public function configure(): void
    {
        $this->setName('converter:callgrind')
            ->setDescription('convert traces to the callgrind file format')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $parser = new PhpSpyCompatibleParser();
        $output->writeln('# format callgrind');
        $output->writeln('events: Samples');
        $output->writeln('');

        $profile = new Profile();
        foreach ($parser->parseFile(STDIN) as $trace) {
            $profile->addTrace($trace);
        }
        foreach ($profile->functions as $function) {
            $output->writeln('fl=' . $function->file_name);
            $output->writeln('fn=' . $function->function_name);
            foreach ($function->lineno_samples as $lineno => $samples) {
                $output->writeln($lineno . ' ' . $samples);
            }
            foreach ($function->calls as $call) {
                $output->writeln('cfl=' . $call->callee->file_name);
                $output->writeln('cfn=' . $call->callee->function_name);
                // We don't know how many calls were made, only the number of samples.
                // We also don't know the start line of the callee.
                $output->writeln('calls=-1 -1');
                $output->writeln($call->caller_lineno . ' ' . $call->samples);
            }
            $output->writeln('');
        }
        return 0;
    }
}
