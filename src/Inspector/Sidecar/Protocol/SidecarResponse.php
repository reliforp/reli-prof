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

namespace Reli\Inspector\Sidecar\Protocol;

final class SidecarResponse
{
    /**
     * @param list<string> $trace
     * @param array<string, mixed>|null $error_context
     * @param array<string, int>|null $memory_stats
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $path = null,
        public readonly ?int $bytes = null,
        public readonly array $trace = [],
        public readonly ?array $error_context = null,
        public readonly ?array $memory_stats = null,
        public readonly ?string $message = null,
    ) {
    }

    /**
     * @param list<string> $trace
     * @param array<string, mixed>|null $error_context
     * @param array<string, int>|null $memory_stats
     */
    public static function ok(
        string $path,
        int $bytes,
        array $trace = [],
        ?array $error_context = null,
        ?array $memory_stats = null,
    ): self {
        return new self(
            status: 'ok',
            path: $path,
            bytes: $bytes,
            trace: $trace,
            error_context: $error_context,
            memory_stats: $memory_stats,
        );
    }

    public static function error(string $message): self
    {
        return new self(
            status: 'error',
            message: $message,
        );
    }

    public function toJson(): string
    {
        $data = ['status' => $this->status];
        if ($this->path !== null) {
            $data['path'] = $this->path;
        }
        if ($this->bytes !== null) {
            $data['bytes'] = $this->bytes;
        }
        if (count($this->trace) > 0) {
            $data['trace'] = $this->trace;
        }
        if ($this->error_context !== null) {
            $data['error_context'] = $this->error_context;
        }
        if ($this->memory_stats !== null) {
            $data['memory_stats'] = $this->memory_stats;
        }
        if ($this->message !== null) {
            $data['message'] = $this->message;
        }
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
