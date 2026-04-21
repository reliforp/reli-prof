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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Emit a standalone HTML page that visualizes a .rmem snapshot in
 * multiple ways (3D force graph, circle packing, treemap, sunburst).
 *
 * The output is a single self-contained HTML file with the extracted
 * subgraph inlined as JSON; the viewer pulls 3d-force-graph and d3
 * from a CDN at runtime.
 */
final class RmemVizCommand extends Command
{
    private const DEFAULT_TOP = 500;
    private const DEFAULT_DEPTH = 1;
    private const MAX_NODES = 5000;

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

        /** @var string|null $outPath */
        $outPath = $input->getOption('output');
        if ($outPath === null || $outPath === '') {
            $outPath = $file . '.viz.html';
        }

        $output->writeln("<info>loading {$file} ...</info>");
        $reader = BinaryReader::open($file);
        $output->writeln('<info>building substrate ...</info>');
        // Non-FFI substrate: simpler load path, ample for the few-thousand
        // node subgraphs this visualizer targets.
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

        [$nodes, $treeEdges, $refEdges] = $this->buildSubgraph(
            $model,
            $substrate,
            $top,
            $depth,
            $allEdges,
        );

        $output->writeln(sprintf(
            '<info>subgraph: %d nodes, %d tree edges, %d ref edges</info>',
            count($nodes),
            count($treeEdges),
            count($refEdges),
        ));

        $payload = [
            'source' => basename($file),
            'node_count_total' => $model->nodeCount,
            'edge_count_total' => $model->edgeCount,
            'top' => $top,
            'depth' => $depth,
            'all_edges' => $allEdges,
            'nodes' => $nodes,
            'tree_edges' => $treeEdges,
            'ref_edges' => $refEdges,
        ];

        $template = $this->loadTemplate();
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($json === false) {
            throw new \RuntimeException('json_encode failed: ' . json_last_error_msg());
        }
        $placeholder = '/*__RMEM_VIZ_DATA__*/null';
        if (!str_contains($template, $placeholder)) {
            throw new \RuntimeException('viz template is missing the data placeholder');
        }
        $html = str_replace($placeholder, $json, $template);

        $outDir = dirname($outPath);
        if (!is_dir($outDir)) {
            mkdir($outDir, 0700, true);
        }
        file_put_contents($outPath, $html);

        $output->writeln("<info>wrote {$outPath}</info>");
        $output->writeln("<info>open it in a browser to explore</info>");
        return 0;
    }

    /**
     * @return array{
     *   0: list<array{id:int,label:string,type:string,class:?string,retained:int,shallow:int,tree_parent:?int,link_name:?string}>,
     *   1: list<array{source:int,target:int,link:string}>,
     *   2: list<array{source:int,target:int}>,
     * }
     */
    private function buildSubgraph(
        RmemModel $model,
        GraphSubstrate $substrate,
        int $top,
        int $depth,
        bool $allEdges,
    ): array {
        $selected = [];
        $seeds = $model->getTopRetained($top);
        foreach ($seeds as $row) {
            $selected[$row['node_id']] = true;
        }

        // Ancestors: for each seed, walk tree parents up to the root so
        // the resulting hierarchy stays connected for treemap/sunburst.
        foreach (array_keys($selected) as $nodeId) {
            $cur = $substrate->getTreeParentNodeId($nodeId);
            while ($cur !== null && !isset($selected[$cur])) {
                $selected[$cur] = true;
                if (count($selected) >= self::MAX_NODES) {
                    break 2;
                }
                $cur = $substrate->getTreeParentNodeId($cur);
            }
        }

        // Downward BFS up to $depth (tree edges only).
        if ($depth > 0) {
            $frontier = array_keys($selected);
            for ($d = 0; $d < $depth; $d++) {
                $next = [];
                foreach ($frontier as $nid) {
                    foreach ($substrate->getChildren($nid) as $childId) {
                        if (!isset($selected[$childId])) {
                            $selected[$childId] = true;
                            $next[] = $childId;
                            if (count($selected) >= self::MAX_NODES) {
                                break 3;
                            }
                        }
                    }
                }
                $frontier = $next;
            }
        }

        $nodes = [];
        foreach (array_keys($selected) as $nodeId) {
            $treeParent = $substrate->getTreeParentNodeId($nodeId);
            $nodes[] = [
                'id' => $nodeId,
                'label' => $model->nodeLabel($nodeId),
                'type' => $substrate->getNodeType($nodeId) ?? '?',
                'class' => $model->resolveClassPublic($nodeId),
                'retained' => $substrate->getSubtreeSize($nodeId),
                'shallow' => $substrate->getNodeSize($nodeId),
                'tree_parent' => (isset($selected[$treeParent ?? -1])) ? $treeParent : null,
                'link_name' => $substrate->getTreeLinkName($nodeId),
            ];
        }

        $treeEdges = [];
        $refEdges = [];
        foreach (array_keys($selected) as $nodeId) {
            $treeChildren = $substrate->getChildren($nodeId);
            $treeChildSet = [];
            foreach ($treeChildren as $childId) {
                if (!isset($selected[$childId])) {
                    continue;
                }
                $treeChildSet[$childId] = true;
                $treeEdges[] = [
                    'source' => $nodeId,
                    'target' => $childId,
                    'link' => $substrate->getTreeLinkName($childId) ?? '',
                ];
            }
            if (!$allEdges) {
                continue;
            }
            foreach ($substrate->getAllChildren($nodeId) as $childId) {
                if (!isset($selected[$childId]) || isset($treeChildSet[$childId])) {
                    continue;
                }
                $refEdges[] = [
                    'source' => $nodeId,
                    'target' => $childId,
                ];
            }
        }

        return [$nodes, $treeEdges, $refEdges];
    }

    private function loadTemplate(): string
    {
        $path = __DIR__ . '/../../../resources/templates/rmem_viz.html';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("cannot load viz template: {$path}");
        }
        return $contents;
    }
}
