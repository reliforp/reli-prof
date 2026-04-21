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

namespace Reli\Command\Inspector;

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Rmem\Explore\RmemModel;
use Reli\Rmem\Live\VizHtmlBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Emit a standalone HTML page that visualizes a .rmem snapshot in
 * multiple ways (3D force graph, circle packing, treemap, sunburst).
 */
final class RmemVizCommand extends Command
{
    private const DEFAULT_TOP = 500;
    private const DEFAULT_DEPTH = 1;

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:rmem:viz')
            ->setDescription('Emit a standalone HTML visualization of a .rmem snapshot')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'path to a .rmem file',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'output HTML path (default: <file>.viz.html)',
            )
            ->addOption(
                'top',
                't',
                InputOption::VALUE_REQUIRED,
                'number of top-retained seed nodes to include',
                (string)self::DEFAULT_TOP,
            )
            ->addOption(
                'depth',
                'd',
                InputOption::VALUE_REQUIRED,
                'downward expansion depth (tree children) from each seed',
                (string)self::DEFAULT_DEPTH,
            )
            ->addOption(
                'all-edges',
                null,
                InputOption::VALUE_NONE,
                'include non-tree reference edges in the force graph',
            )
            ->addOption(
                'max-nodes',
                null,
                InputOption::VALUE_REQUIRED,
                'hard cap on total nodes in the extracted subgraph',
                (string)\Reli\Rmem\Live\VizHtmlBuilder::DEFAULT_MAX_NODES,
            )
            ->addOption(
                'memory-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'set PHP memory_limit (e.g. 4G, 512M)',
            )
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $memoryLimit */
        $memoryLimit = $input->getOption('memory-limit');
        if (is_string($memoryLimit) && $memoryLimit !== '') {
            ini_set('memory_limit', $memoryLimit);
        }

        $file = (string)$input->getArgument('file');
        if (!is_file($file)) {
            $output->writeln("<error>File not found: {$file}</error>");
            return 1;
        }

        $top = max(1, (int)$input->getOption('top'));
        $depth = max(0, (int)$input->getOption('depth'));
        $allEdges = (bool)$input->getOption('all-edges');
        $maxNodes = max(1, (int)$input->getOption('max-nodes'));

        /** @var string|null $outPath */
        $outPath = $input->getOption('output');
        if ($outPath === null || $outPath === '') {
            $outPath = $file . '.viz.html';
        }

        $output->writeln("<info>loading {$file} ...</info>");
        $reader = BinaryReader::open($file);
        $output->writeln('<info>building substrate ...</info>');
        $substrate = GraphSubstrate::createFromBinary($reader, forceFfiCsr: false, skipScc: true);
        $output->writeln('<info>building model ...</info>');
        $model = RmemModel::fromSubstrate($substrate, $reader);
        $model->ensureLocationInfoLoaded();

        $output->writeln(sprintf(
            '<info>graph: %s nodes, %s edges. selecting top-%d subgraph...</info>',
            number_format($model->nodeCount),
            number_format($model->edgeCount),
            $top,
        ));

        $html = VizHtmlBuilder::build(
            $model,
            $substrate,
            $file,
            $top,
            $depth,
            $allEdges,
            liveMode: false,
            maxNodes: $maxNodes,
        );

        $outDir = dirname($outPath);
        if (!is_dir($outDir)) {
            mkdir($outDir, 0700, true);
        }
        file_put_contents($outPath, $html);

        $output->writeln("<info>wrote {$outPath}</info>");
        $output->writeln("<info>open it in a browser to explore</info>");
        return 0;
    }
}
