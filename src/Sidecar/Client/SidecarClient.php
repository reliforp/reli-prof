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

namespace Reli\Sidecar\Client;

/**
 * Lightweight client for communicating with the reli sidecar daemon.
 *
 * This class requires no FFI and no heavy dependencies.
 * It is designed to be usable from within a PHP application's
 * shutdown handler with minimal memory overhead.
 *
 * Socket path resolution order:
 *   1. Constructor argument ($socket_path)
 *   2. RELI_SIDECAR_SOCKET environment variable
 *   3. Default: /var/run/reli-sidecar.sock
 */
final class SidecarClient
{
    public const DEFAULT_SOCKET_PATH = '/var/run/reli-sidecar.sock';
    public const ENV_SOCKET_PATH = 'RELI_SIDECAR_SOCKET';

    private string $socket_path;

    public function __construct(
        ?string $socket_path = null,
        private int $timeout_seconds = 30,
    ) {
        $this->socket_path = $socket_path
            ?? ($_SERVER[self::ENV_SOCKET_PATH] ?? null)
            ?? (getenv(self::ENV_SOCKET_PATH) ?: null)
            ?? self::DEFAULT_SOCKET_PATH;
    }

    /**
     * Request a memory dump of the given process.
     *
     * @param int $pid target process ID
     * @param string|null $error_file file where the error occurred
     * @param int|null $error_line line where the error occurred
     * @param string|null $label human-readable label for the snapshot
     * @param array<string, string> $metadata arbitrary key-value pairs
     * @return SidecarClientResponse|null null on connection failure
     */
    public function requestDump(
        int $pid,
        ?string $error_file = null,
        ?int $error_line = null,
        ?string $label = null,
        array $metadata = [],
    ): ?SidecarClientResponse {
        $payload = ['command' => 'dump', 'pid' => $pid];
        if ($error_file !== null) {
            $payload['file'] = $error_file;
        }
        if ($error_line !== null) {
            $payload['line'] = $error_line;
        }
        if ($label !== null) {
            $payload['label'] = $label;
        }
        if (count($metadata) > 0) {
            $payload['metadata'] = $metadata;
        }

        return $this->send($payload);
    }

    /**
     * Take a named snapshot of the current process.
     *
     * Convenience wrapper for CI / benchmarking scripts:
     *   $client->snapshot('after-fixtures');
     *   $client->snapshot('post-processing', ['commit' => 'abc123']);
     *
     * @param string $label human-readable label
     * @param array<string, string> $metadata arbitrary key-value pairs (commit SHA, PHP version, etc.)
     * @return SidecarClientResponse|null null on connection failure
     */
    public function snapshot(
        string $label,
        array $metadata = [],
    ): ?SidecarClientResponse {
        $pid = getmypid();
        if ($pid === false) {
            return null;
        }
        return $this->requestDump(
            pid: $pid,
            label: $label,
            metadata: $metadata,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(array $payload): ?SidecarClientResponse
    {
        $sock = @stream_socket_client(
            "unix://{$this->socket_path}",
            $errno,
            $errstr,
            $this->timeout_seconds,
        );
        if ($sock === false) {
            return null;
        }

        try {
            stream_set_timeout($sock, $this->timeout_seconds);

            $written = fwrite($sock, json_encode($payload) . "\n");
            if ($written === false) {
                return null;
            }

            $response = fgets($sock, 65536);
            if ($response === false || $response === '') {
                return null;
            }

            return SidecarClientResponse::fromJson(trim($response));
        } finally {
            fclose($sock);
        }
    }
}
