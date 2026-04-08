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
 */
final class MemoryLimitHandler
{
    /**
     * @param string|null $socket_path UDS path (null = auto-detect)
     * @param (callable(SidecarClientResponse): void)|null $on_response
     * @param (callable(string): void)|null $on_error called with error message on failure
     */
    public static function register(
        ?string $socket_path = null,
        ?callable $on_response = null,
        ?callable $on_error = null,
    ): void {
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
