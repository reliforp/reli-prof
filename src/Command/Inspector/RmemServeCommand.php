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
use Reli\Rmem\Serve\RmemQueryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Headless query server for .rmem files over Unix domain socket.
 */
final class RmemServeCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:rmem:serve')
            ->setDescription('Start a persistent query server for a .rmem file')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                '.rmem file path',
            )
            ->addOption(
                'socket',
                null,
                InputOption::VALUE_REQUIRED,
                'Unix domain socket path (default: auto-generated in $XDG_RUNTIME_DIR or /tmp)',
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Auto-shutdown after N seconds of inactivity (default: 3600, 0 = never)',
                '3600',
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

        /** @var string|null $socketOpt */
        $socketOpt = $input->getOption('socket');
        $socketPath = is_string($socketOpt) ? $socketOpt : $this->defaultSocketPath();
        $timeout = (int)$input->getOption('timeout');

        $output->writeln("<info>loading {$file} ...</info>");
        $reader = BinaryReader::open($file);
        $substrate = GraphSubstrate::createFromBinary($reader, skipScc: true);
        $model = RmemModel::fromSubstrate($substrate, $reader);
        $reader->clearCastCache();

        // Prewarm
        $output->writeln('<info>prewarming caches ...</info>');
        $model->ensureLocationInfoLoaded();
        $model->getClassRanking();
        $model->getTypeRanking();
        $model->buildDefinitionIndexes();

        $serverId = bin2hex(random_bytes(6));
        $service = new RmemQueryService($model, $file, $serverId);

        // Create socket directory
        $socketDir = dirname($socketPath);
        if (!is_dir($socketDir)) {
            mkdir($socketDir, 0700, true);
        }

        // Bind socket
        if (file_exists($socketPath)) {
            @unlink($socketPath);
        }
        $server = @stream_socket_server("unix://{$socketPath}", $errno, $errstr);
        if ($server === false) {
            $output->writeln("<error>Cannot bind socket: {$errstr} ({$errno})</error>");
            return 1;
        }
        chmod($socketPath, 0600);

        // Write PID file
        $pidPath = $socketPath . '.pid';
        file_put_contents($pidPath, (string)getmypid());

        $output->writeln(sprintf(
            '<info>serving on %s (server_id=%s, %s nodes, %s edges)</info>',
            $socketPath,
            $serverId,
            number_format($model->nodeCount),
            number_format($model->edgeCount),
        ));
        $output->writeln('<info>Ctrl+C to stop</info>');

        // Install signal handlers
        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use (&$running) {
                $running = false;
            });
            pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
        }

        $lastActivity = time();

        while ($running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            // Timeout check
            if ($timeout > 0 && (time() - $lastActivity) > $timeout) {
                $output->writeln('<info>Timeout — shutting down</info>');
                break;
            }

            // Accept with timeout so we can check signals
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

            $lastActivity = time();

            // Handle requests on this connection
            while ($running && !feof($client)) {
                $line = fgets($client);
                if ($line === false || $line === '') {
                    break;
                }
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    $response = ['ok' => false, 'error' => 'Invalid JSON'];
                } else {
                    /** @var array<string, mixed> $request */
                    $request = $decoded;
                    if ((string)($request['action'] ?? '') === 'server.shutdown') {
                        $response = ['ok' => true, 'data' => 'shutting down'];
                        fwrite($client, (string)json_encode($response) . "\n");
                        $running = false;
                        break;
                    }
                    $response = $service->handle($request);
                }

                fwrite($client, (string)json_encode($response, JSON_UNESCAPED_UNICODE) . "\n");
                $lastActivity = time();
            }

            fclose($client);
        }

        // Cleanup
        fclose($server);
        @unlink($socketPath);
        @unlink($pidPath);
        $output->writeln('<info>Server stopped</info>');

        return 0;
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
