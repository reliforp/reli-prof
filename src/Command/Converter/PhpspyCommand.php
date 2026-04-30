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

use Reli\Converter\PhpSpyCompatibleFormatter;
use Reli\Converter\TraceInputReader;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PhpspyCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('converter:phpspy')
            ->setDescription('convert traces to phpspy-compatible text (auto-detects rbt or phpspy input)')
            ->addMemoryLimitOption()
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);
        $reader = new TraceInputReader();
        $formatter = new PhpSpyCompatibleFormatter();

        foreach ($reader->read(STDIN) as $trace) {
            $output->write($formatter->formatTrace($trace));
        }

        return 0;
    }
}
