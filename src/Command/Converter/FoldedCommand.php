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

use Reli\Converter\BinaryTrace\FoldedStacksFormatter;
use Reli\Converter\TraceInputReader;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class FoldedCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('converter:folded')
            ->setDescription('convert traces to folded stacks format (auto-detects rbt or phpspy input)')
            ->addOption(
                'memory-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'set PHP memory_limit for analysis (e.g. 2G, 512M)',
            )
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $memory_limit */
        $memory_limit = $input->getOption('memory-limit');
        if (is_string($memory_limit) && $memory_limit !== '') {
            ini_set('memory_limit', $memory_limit);
        }
        $reader = new TraceInputReader();
        $formatter = new FoldedStacksFormatter();

        $output->write($formatter->format($reader->read(STDIN)));

        return 0;
    }
}
