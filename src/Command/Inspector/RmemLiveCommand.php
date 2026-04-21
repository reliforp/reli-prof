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
use Reli\Rmem\Live\LiveHttpServer;
use Reli\Rmem\Live\VizHtmlBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Serve the rmem viz over HTTP with a live SSE event channel so multiple
 * browsers (and, eventually, the explore TUI / MCP agents) can share a
 * single focused-node cursor.
 *
 * Endpoints exposed by the embedded HTTP server:
 *   GET  /                viz HTML pre-baked with the chosen subgraph
 *   GET  /api/events      SSE stream of focus_node events
 *   POST /api/navigate    JSON {"node_id": int} → broadcast focus_node
 */
final class RmemLiveCommand extends Command
{
    private const DEFAULT_TOP = 500;
    private const DEFAULT_DEPTH = 1;
    private const DEFAULT_PORT = 8080;
    private const DEFAULT_HOST = '127.0.0.1';

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:rmem:live')
            ->setDescription('Serve the rmem viz over HTTP with a live SSE event channel')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'path to a .rmem file',
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                'bind host',
                self::DEFAULT_HOST,
            )
            ->addOption(
                'port',
                'p',
                InputOption::VALUE_REQUIRED,
                'TCP port',
                (string)self::DEFAULT_PORT,
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
                (string)VizHtmlBuilder::DEFAULT_MAX_NODES,
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
        $host = (string)$input->getOption('host');
        $port = max(1, (int)$input->getOption('port'));

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
            liveMode: true,
            extraPayload: [
                'live' => [
                    'events_url' => '/api/events',
                    'navigate_url' => '/api/navigate',
                ],
            ],
            maxNodes: $maxNodes,
        );

        $server = new LiveHttpServer($html, $host, $port);
        // Let browsers find a visible ancestor when we focus a node
        // that's outside their induced subgraph.
        $server->setFocusEnricher(static function (int $nodeId) use ($substrate, $model): array {
            $path = [];
            $cur = $nodeId;
            $hops = 0;
            while ($cur !== null && $hops < 64) {
                $path[] = $cur;
                $cur = $substrate->getTreeParentNodeId($cur);
                $hops++;
            }
            return [
                'path_node_ids' => array_reverse($path),
                'label' => $model->nodeLabel($nodeId),
            ];
        });
        $addr = $server->bind();
        $output->writeln(sprintf(
            '<info>listening on %s — open http://%s:%d/ in a browser</info>',
            $addr,
            $host,
            $port,
        ));
        $output->writeln('<info>POST {"node_id": N} to /api/navigate to broadcast a focus event</info>');
        $output->writeln('<info>Ctrl+C to stop</info>');

        $server->run();

        $output->writeln('<info>server stopped</info>');
        return 0;
    }
}
