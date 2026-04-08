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
 * Registers a shutdown handler that requests a memory dump
 * from the reli sidecar daemon when a memory_limit error occurs.
 *
 * Usage:
 *   MemoryLimitHandler::register();
 *
 * Or with options:
 *   MemoryLimitHandler::register(
 *       socket_path: '/custom/path.sock',
 *       on_response: function (SidecarClientResponse $r) {
 *           error_log('reli dump: ' . $r->path);
 *       },
 *   );
 *
 * Socket path is resolved via:
 *   1. $socket_path argument
 *   2. RELI_SIDECAR_SOCKET environment variable
 *   3. Default: /var/run/reli-sidecar.sock
 *
 * ## Emergency Memory Reserve
 *
 * When PHP hits memory_limit, the shutdown handler runs with almost no
 * free memory. Even `stream_socket_client()` needs to allocate buffers.
 * To ensure the handler can function, this class pre-allocates a block
 * of memory (default 256 KB) at `register()` time and releases it at
 * the very start of the shutdown handler, freeing enough headroom for
 * the socket operations.
 *
 * Adjust the reserve size via the $reserve_bytes parameter if needed.
 */
final class MemoryLimitHandler
{
    private const DEFAULT_RESERVE_BYTES = 256 * 1024;

    /** @var string|null Pre-allocated memory block, released on shutdown */
    private static ?string $reserve = null;

    /**
     * @param string|null $socket_path UDS path (null = auto-detect)
     * @param (callable(SidecarClientResponse): void)|null $on_response
     * @param (callable(string): void)|null $on_error called with error message on failure
     * @param int $reserve_bytes emergency memory reserve size (default: 256 KB)
     */
    public static function register(
        ?string $socket_path = null,
        ?callable $on_response = null,
        ?callable $on_error = null,
        int $reserve_bytes = self::DEFAULT_RESERVE_BYTES,
    ): void {
        self::$reserve = str_repeat("\0", $reserve_bytes);
        register_shutdown_function(
            self::createHandler($socket_path, $on_response, $on_error),
        );
    }

    /**
     * @param string|null $socket_path
     * @param (callable(SidecarClientResponse): void)|null $on_response
     * @param (callable(string): void)|null $on_error
     * @return \Closure
     */
    private static function createHandler(
        ?string $socket_path,
        ?callable $on_response,
        ?callable $on_error,
    ): \Closure {
        return static function () use ($socket_path, $on_response, $on_error): void {
            // Release emergency reserve to free memory for socket operations
            self::$reserve = null;

            $error = error_get_last();
            if ($error === null) {
                return;
            }

            if (!self::isMemoryLimitError($error['message'])) {
                return;
            }

            $pid = getmypid();
            if ($pid === false) {
                return;
            }

            $client = new SidecarClient($socket_path);
            $response = $client->requestDump(
                pid: $pid,
                error_file: $error['file'] ?? null,
                error_line: $error['line'] ?? null,
            );

            if ($response === null) {
                if ($on_error !== null) {
                    $on_error('failed to connect to reli sidecar');
                }
                return;
            }

            if ($on_response !== null) {
                $on_response($response);
            } elseif ($response->isOk()) {
                error_log(sprintf(
                    'reli-sidecar: memory dump saved to %s (%.1f MB)',
                    $response->path ?? '',
                    (float)($response->bytes ?? 0) / 1024.0 / 1024.0,
                ));
            } else {
                error_log(sprintf(
                    'reli-sidecar: dump failed: %s',
                    $response->message ?? 'unknown error',
                ));
            }
        };
    }

    private static function isMemoryLimitError(string $message): bool
    {
        return str_contains($message, 'Allowed memory size');
    }
}
