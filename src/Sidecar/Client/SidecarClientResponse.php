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

final class SidecarClientResponse
{
    /**
     * @param list<string> $trace
     * @param array<string, mixed>|null $error_context
     * @param array<string, int>|null $memory_stats heap stats and RSS at snapshot time
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

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }

    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['status']) || !is_string($data['status'])) {
            return null;
        }
        /** @var list<string> $trace */
        $trace = isset($data['trace']) && is_array($data['trace'])
            ? array_values(array_filter($data['trace'], 'is_string'))
            : [];
        /** @var array<string, mixed>|null $error_context */
        $error_context = isset($data['error_context']) && is_array($data['error_context'])
            ? $data['error_context']
            : null;
        /** @var array<string, int>|null $memory_stats */
        $memory_stats = isset($data['memory_stats']) && is_array($data['memory_stats'])
            ? $data['memory_stats']
            : null;

        return new self(
            status: $data['status'],
            path: isset($data['path']) && is_string($data['path']) ? $data['path'] : null,
            bytes: isset($data['bytes']) && is_int($data['bytes']) ? $data['bytes'] : null,
            trace: $trace,
            error_context: $error_context,
            memory_stats: $memory_stats,
            message: isset($data['message']) && is_string($data['message']) ? $data['message'] : null,
        );
    }
}
