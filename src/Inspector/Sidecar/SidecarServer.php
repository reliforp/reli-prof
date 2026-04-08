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

namespace Reli\Inspector\Sidecar;

use Reli\Inspector\Sidecar\Protocol\SidecarRequest;
use Reli\Inspector\Sidecar\Protocol\SidecarResponse;
use Reli\Lib\Log\Log;
use Symfony\Component\Console\Output\OutputInterface;

final class SidecarServer
{
    private bool $running = false;

    public function __construct(
        private SidecarDumpHandler $dump_handler,
    ) {
    }

    public function run(string $socket_path, OutputInterface $output): void
    {
        $this->cleanup($socket_path);
        $this->running = true;

        $server = stream_socket_server(
            "unix://{$socket_path}",
            $errno,
            $errstr,
        );
        if ($server === false) {
            throw new \RuntimeException(
                "Failed to create socket at {$socket_path}: [{$errno}] {$errstr}"
            );
        }

        // Allow group read/write so app processes in the same group can connect
        chmod($socket_path, 0660);

        // Clean up socket file on shutdown
        $cleanup = function () use ($socket_path, $server): void {
            $this->running = false;
            fclose($server);
            $this->cleanup($socket_path);
        };

        pcntl_signal(SIGTERM, $cleanup);
        pcntl_signal(SIGINT, $cleanup);

        $output->writeln(sprintf(
            '<info>[sidecar] Listening on %s</info>',
            $socket_path,
        ));

        /** @psalm-suppress RedundantCondition -- $this->running is set to false by signal handler */
        while ($this->running) {
            // Use stream_select to allow signal handling between polls
            $read = [$server];
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 1);

            pcntl_signal_dispatch();

            /** @psalm-suppress TypeDoesNotContainType -- signal handler sets $this->running = false */
            if (!$this->running) {
                break;
            }

            if ($changed === false || $changed === 0) {
                continue;
            }

            $client = @stream_socket_accept($server, 5);
            if ($client === false) {
                continue;
            }

            $this->handleConnection($client, $output);
        }

        $output->writeln('<info>[sidecar] Stopped.</info>');
    }

    /**
     * @param resource $client
     */
    private function handleConnection($client, OutputInterface $output): void
    {
        try {
            stream_set_timeout($client, 5);
            $line = fgets($client, 8192);
            if ($line === false || $line === '') {
                return;
            }

            $request = SidecarRequest::fromJson(trim($line));
            if ($request === null) {
                $response = SidecarResponse::error('invalid request');
                fwrite($client, $response->toJson() . "\n");
                return;
            }

            if ($request->command !== 'dump') {
                $response = SidecarResponse::error("unknown command: {$request->command}");
                fwrite($client, $response->toJson() . "\n");
                return;
            }

            $context_parts = [];
            if ($request->label !== null) {
                $context_parts[] = sprintf('label=%s', $request->label);
            }
            if ($request->error_file !== null) {
                $context_parts[] = sprintf('file=%s line=%d', $request->error_file, $request->error_line ?? 0);
            }
            $output->writeln(sprintf(
                '<info>[sidecar] Dump request: PID=%d%s</info>',
                $request->pid,
                count($context_parts) > 0 ? ' ' . implode(' ', $context_parts) : '',
            ));

            $response = $this->dump_handler->handle($request);

            fwrite($client, $response->toJson() . "\n");

            if ($response->status === 'ok') {
                $output->writeln(sprintf(
                    '<info>[sidecar] Dump complete: %s (%.1f MB)</info>',
                    $response->path ?? '',
                    (float)($response->bytes ?? 0) / 1024.0 / 1024.0,
                ));
            } else {
                $output->writeln(sprintf(
                    '<comment>[sidecar] Dump failed: %s</comment>',
                    $response->message ?? 'unknown error',
                ));
            }
        } catch (\Throwable $e) {
            Log::debug('sidecar connection error', [
                'error' => $e->getMessage(),
            ]);
            $response = SidecarResponse::error($e->getMessage());
            @fwrite($client, $response->toJson() . "\n");
        } finally {
            fclose($client);
        }
    }

    private function cleanup(string $socket_path): void
    {
        if (file_exists($socket_path)) {
            unlink($socket_path);
        }
    }
}
