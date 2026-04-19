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
use Reli\Rbt\Explore\Keymap;
use Reli\Rbt\Explore\Terminal;
use Reli\Rmem\Serve\RmemQueryService;
use Reli\Rmem\Explore\RmemExploreTui;
use Reli\Rmem\Explore\RmemModel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interactive TUI for browsing a .rmem memory snapshot.
 */
final class RmemExploreCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:rmem:explore')
            ->setDescription('Interactive TUI explorer for .rmem memory snapshots')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'path to a .rmem file',
            )
            ->addOption(
                'keymap',
                null,
                InputOption::VALUE_REQUIRED,
                'path to a JSON keymap file overriding the defaults',
            )
            ->addOption(
                'node',
                null,
                InputOption::VALUE_REQUIRED,
                'start with sandwich view focused on this node ID',
            )
            ->addOption(
                'address',
                null,
                InputOption::VALUE_REQUIRED,
                'start with sandwich view focused on the node at this address (hex or decimal)',
            )
            ->addOption(
                'memory-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'set PHP memory_limit (e.g. 4G, 512M)',
            )
            ->addOption(
                'serve',
                null,
                InputOption::VALUE_OPTIONAL,
                'fork a query server child on the given Unix socket path (or auto-generated)',
                false, // false = not passed, null = passed without value
            )
            ->addOption(
                'serve-control',
                null,
                InputOption::VALUE_NONE,
                'enable ui.* actions in serve protocol (allows clients to navigate TUI)',
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

        $file = (string) $input->getArgument('file');
        if (!is_file($file)) {
            $output->writeln("<error>File not found: {$file}</error>");
            return 1;
        }

        /** @var string|null $keymap_path */
        $keymap_path = $input->getOption('keymap');
        $keymap = $keymap_path !== null
            ? Keymap::fromJsonFile($keymap_path)
            : Keymap::default();

        $output->writeln("<info>loading {$file} ...</info>");
        $reader = BinaryReader::open($file);
        $output->writeln('<info>building substrate ...</info>');
        $substrate = GraphSubstrate::createFromBinary($reader, skipScc: true);
        $output->writeln('<info>building model ...</info>');
        $model = RmemModel::fromSubstrate($substrate, $reader);
        $output->writeln(sprintf(
            '<info>loaded %s edges, starting TUI</info>',
            number_format($model->edgeCount),
        ));
        $reader->clearCastCache();

        // Resolve initial node from --node or --address
        $initialNodeId = null;
        /** @var string|null $nodeOpt */
        $nodeOpt = $input->getOption('node');
        /** @var string|null $addrOpt */
        $addrOpt = $input->getOption('address');
        if ($nodeOpt !== null) {
            $initialNodeId = (int)$nodeOpt;
        } elseif ($addrOpt !== null) {
            $addr = str_starts_with($addrOpt, '0x')
                ? (int)hexdec(substr($addrOpt, 2))
                : (int)$addrOpt;
            // Find node by address from locations
            $model->ensureLocationInfoLoaded();
            $initialNodeId = $model->findNodeByAddress($addr);
            if ($initialNodeId === null) {
                $output->writeln("<error>No node found at address 0x" . dechex($addr) . "</error>");
                return 1;
            }
        }

        // --serve: fork a query server child before starting TUI
        $serveOpt = $input->getOption('serve');
        $serveControl = (bool) $input->getOption('serve-control');
        $queryChildPid = null;
        $socketPath = null;
        /** @var resource|null $uiRequestRead pipe for child→parent ui.* requests */
        $uiRequestRead = null;
        /** @var resource|null $uiResponseWrite pipe for parent→child ui.* responses */
        $uiResponseWrite = null;
        if ($serveOpt !== false) {
            // Prewarm before fork
            $model->ensureLocationInfoLoaded();
            $model->getClassRanking();
            $model->getTypeRanking();
            $model->buildDefinitionIndexes();

            $socketPath = is_string($serveOpt) && $serveOpt !== ''
                ? $serveOpt
                : $this->defaultSocketPath();
            $socketDir = dirname($socketPath);
            if (!is_dir($socketDir)) {
                mkdir($socketDir, 0700, true);
            }

            // Create IPC pipes for ui.* actions (child→parent request, parent→child response)
            $uiRequestPipe = null;
            $uiResponsePipe = null;
            if ($serveControl) {
                $uiRequestPipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                $uiResponsePipe = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                if ($uiRequestPipe === false || $uiResponsePipe === false) {
                    $output->writeln('<error>Failed to create IPC pipes</error>');
                    return 1;
                }
            }

            $serverId = bin2hex(random_bytes(6));
            $queryChildPid = pcntl_fork();
            if ($queryChildPid === -1) {
                $output->writeln('<error>Fork failed</error>');
                return 1;
            }
            if ($queryChildPid === 0) {
                // Child: close parent ends of pipes
                if ($uiRequestPipe !== null && $uiResponsePipe !== null) {
                    fclose($uiRequestPipe[0]); // parent reads from [0]
                    fclose($uiResponsePipe[1]); // parent writes to [1]
                    $this->runQueryChild(
                        $model,
                        $file,
                        $serverId,
                        $socketPath,
                        $uiRequestPipe[1],  // child writes requests
                        $uiResponsePipe[0], // child reads responses
                    );
                } else {
                    $this->runQueryChild($model, $file, $serverId, $socketPath);
                }
                exit(0);
            }
            // Parent: close child ends of pipes
            if ($uiRequestPipe !== null && $uiResponsePipe !== null) {
                fclose($uiRequestPipe[1]); // child writes to [1]
                fclose($uiResponsePipe[0]); // child reads from [0]
                $uiRequestRead = $uiRequestPipe[0];
                $uiResponseWrite = $uiResponsePipe[1];
                stream_set_blocking($uiRequestRead, false);
            }
            $output->writeln("<info>query server forked (pid={$queryChildPid}, socket={$socketPath})</info>");
        }

        // Fork SCC builder if sidecar doesn't already have SCC
        $sccBuilderPid = null;
        if (extension_loaded('pcntl')) {
            $rmemPath = realpath($file) ?: $file;
            $cacheReader = \Reli\Inspector\Output\MemoryOutput\BinaryFormat\DerivedCacheReader::open($rmemPath);
            $hasScc = $cacheReader !== null
                && $cacheReader->hasSection(\Reli\Inspector\Output\MemoryOutput\BinaryFormat\DerivedCacheFormat::SECTION_SCC_NODE_MAP);
            if (!$hasScc) {
                $sccBuilderPid = pcntl_fork();
                if ($sccBuilderPid === 0) {
                    // Child: compute SCC and write sidecar
                    $this->runSccBuilder($file);
                    exit(0);
                }
                if ($sccBuilderPid > 0) {
                    $output->writeln("<info>SCC builder forked (pid={$sccBuilderPid})</info>");
                }
            }
        }

        $term = new Terminal();
        $tui = new RmemExploreTui($model, $term, $keymap, $initialNodeId, $socketPath);
        if ($queryChildPid !== null) {
            $tui->setQueryChildPid($queryChildPid);
        }
        if ($sccBuilderPid !== null && $sccBuilderPid > 0) {
            $tui->setSccBuilderPid($sccBuilderPid);
        }
        if ($uiRequestRead !== null && $uiResponseWrite !== null) {
            $tui->setUiPipes($uiRequestRead, $uiResponseWrite);
        }

        try {
            $tui->run();
        } finally {
            // Cleanup children
            if ($queryChildPid !== null && $queryChildPid > 0) {
                posix_kill($queryChildPid, SIGTERM);
                pcntl_waitpid($queryChildPid, $status, WNOHANG);
            }
            if ($sccBuilderPid !== null && $sccBuilderPid > 0) {
                posix_kill($sccBuilderPid, SIGTERM);
                pcntl_waitpid($sccBuilderPid, $status, WNOHANG);
            }
            if ($uiRequestRead !== null) {
                @fclose($uiRequestRead);
            }
            if ($uiResponseWrite !== null) {
                @fclose($uiResponseWrite);
            }
            if ($socketPath !== null) {
                @unlink($socketPath);
                @unlink($socketPath . '.pid');
            }
        }

        return 0;
    }

    /**
     * @param resource|null $uiRequestWrite  pipe to send ui.* requests to parent
     * @param resource|null $uiResponseRead  pipe to receive ui.* responses from parent
     */
    private function runQueryChild(
        RmemModel $model,
        string $rmemPath,
        string $serverId,
        string $socketPath,
        mixed $uiRequestWrite = null,
        mixed $uiResponseRead = null,
    ): never {
        $service = new RmemQueryService($model, $rmemPath, $serverId);

        if (file_exists($socketPath)) {
            @unlink($socketPath);
        }
        $server = @stream_socket_server("unix://{$socketPath}", $errno, $errstr);
        if ($server === false) {
            fwrite(STDERR, "Query child: cannot bind socket: {$errstr}\n");
            exit(1);
        }
        chmod($socketPath, 0600);
        file_put_contents($socketPath . '.pid', (string)getmypid());

        $running = true;
        pcntl_signal(SIGTERM, function () use (&$running) {
            $running = false;
        });

        while ($running) {
            pcntl_signal_dispatch();

            $read = [$server];
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 1);
            if ($changed === false || $changed === 0) {
                continue;
            }

            $client = @stream_socket_accept($server, 0);
            if ($client === false) {
                continue;
            }

            while ($running && !feof($client)) {
                $line = fgets($client);
                if ($line === false || $line === '') {
                    break;
                }
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $request = json_decode($line, true);
                if (!is_array($request)) {
                    $response = ['ok' => false, 'error' => 'Invalid JSON'];
                } else {
                    $action = (string)($request['action'] ?? '');
                    if (str_starts_with($action, 'ui.')) {
                        $response = $this->forwardUiRequest($request, $uiRequestWrite, $uiResponseRead);
                    } else {
                        $response = $service->handle($request);
                        // Annotate hello with ui capabilities
                        if ($action === 'server.hello' && isset($response['data'])) {
                            $response['data']['serve_control'] = $uiRequestWrite !== null;
                        }
                    }
                }
                fwrite($client, json_encode($response, JSON_UNESCAPED_UNICODE) . "\n");
            }
            fclose($client);
        }

        fclose($server);
        @unlink($socketPath);
        @unlink($socketPath . '.pid');
        exit(0);
    }

    /**
     * Forward a ui.* request to the parent TUI via pipe and wait for response.
     *
     * @param array<string, mixed> $request
     * @param resource|null $uiRequestWrite
     * @param resource|null $uiResponseRead
     * @return array<string, mixed>
     */
    private function forwardUiRequest(array $request, mixed $uiRequestWrite, mixed $uiResponseRead): array
    {
        if ($uiRequestWrite === null || $uiResponseRead === null) {
            return ['ok' => false, 'error' => 'ui.* actions require --serve-control'];
        }

        $payload = json_encode($request, JSON_UNESCAPED_UNICODE) . "\n";
        $written = @fwrite($uiRequestWrite, $payload);
        if ($written === false || $written === 0) {
            return ['ok' => false, 'error' => 'Failed to forward ui request to parent'];
        }
        fflush($uiRequestWrite);

        // Wait for response with 5s timeout
        $read = [$uiResponseRead];
        $write = null;
        $except = null;
        $changed = @stream_select($read, $write, $except, 5);
        if ($changed === false || $changed === 0) {
            return ['ok' => false, 'error' => 'Timeout waiting for ui response from parent'];
        }

        $line = fgets($uiResponseRead);
        if ($line === false || $line === '') {
            return ['ok' => false, 'error' => 'Failed to read ui response from parent'];
        }

        $response = json_decode(trim($line), true);
        return is_array($response) ? $response : ['ok' => false, 'error' => 'Invalid response from parent'];
    }

    private function runSccBuilder(string $rmemPath): never
    {
        try {
            $reader = BinaryReader::open($rmemPath);
            GraphSubstrate::createFromBinary($reader, skipScc: false, forceFfiCsr: true);
            exit(0);
        } catch (\Throwable $e) {
            fwrite(STDERR, "SCC builder failed: {$e->getMessage()}\n");
            exit(1);
        }
    }

    private function defaultSocketPath(): string
    {
        $runtimeDir = getenv('XDG_RUNTIME_DIR');
        if ($runtimeDir !== false && $runtimeDir !== '') {
            $dir = $runtimeDir . '/reli/rmem-serve';
        } else {
            $dir = sys_get_temp_dir() . '/reli-rmem-serve';
        }
        $id = bin2hex(random_bytes(4));
        return "{$dir}/{$id}.sock";
    }
}
