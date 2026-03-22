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

namespace Reli\Command\Generator;

use Reli\Lib\Session\SessionEnumDiscoverer;
use Reli\Lib\Session\SessionGraph;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GenerateDiagramCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('generator:diagram')
            ->setDescription('Output Mermaid state diagrams for all session protocols');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $enumClasses = SessionEnumDiscoverer::discover(dirname(__DIR__, 3) . '/src');

        if ($enumClasses === []) {
            $output->writeln('<comment>No session protocol enums found.</comment>');
            return 0;
        }

        foreach ($enumClasses as $enumClass) {
            $graph = SessionGraph::fromEnum($enumClass);

            $output->writeln("## {$graph->protocol->name}Session");
            $output->writeln('');
            $output->writeln('```mermaid');
            $output->write($graph->toMermaid());
            $output->writeln('```');
            $output->writeln('');
        }

        return 0;
    }
}
