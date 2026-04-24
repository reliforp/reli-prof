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

use Reli\Inspector\Sidecar\SocketPathResolver;

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
 *   3. $XDG_RUNTIME_DIR/reli/sidecar.sock (or throws if XDG_RUNTIME_DIR unset)
 */
final class SidecarClient
{
    public const ENV_SOCKET_PATH = 'RELI_SIDECAR_SOCKET';

    private string $socket_path;

    /**
     * @param array<string, string> $default_metadata merged into every request (per-call metadata wins)
     */
    public function __construct(
        ?string $socket_path = null,
        private int $timeout_seconds = 30,
        private array $default_metadata = [],
    ) {
        if ($socket_path !== null) {
            $this->socket_path = $socket_path;
        } else {
            $env = getenv(self::ENV_SOCKET_PATH);
            if (is_string($env) && $env !== '') {
                $this->socket_path = $env;
            } else {
                $this->socket_path = SocketPathResolver::resolveDefault();
            }
        }
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
        $merged = array_merge($this->default_metadata, $metadata);
        if (count($merged) > 0) {
            $payload['metadata'] = $merged;
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

            $written = fwrite($sock, (string)json_encode($payload) . "\n");
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
