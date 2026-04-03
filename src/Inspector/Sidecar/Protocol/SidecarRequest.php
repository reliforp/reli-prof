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

final class SidecarRequest
{
    /**
     * @param array<string, string> $metadata arbitrary key-value pairs (e.g. commit SHA, PHP version, env)
     */
    public function __construct(
        public readonly string $command,
        public readonly int $pid,
        public readonly ?string $error_file = null,
        public readonly ?int $error_line = null,
        public readonly ?string $label = null,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return self|null null on invalid JSON
     */
    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $command = $data['command'] ?? null;
        $pid = $data['pid'] ?? null;
        if (!is_string($command) || !is_int($pid)) {
            return null;
        }

        $metadata = [];
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            foreach ($data['metadata'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $metadata[$k] = $v;
                }
            }
        }

        return new self(
            command: $command,
            pid: $pid,
            error_file: isset($data['file']) && is_string($data['file']) ? $data['file'] : null,
            error_line: isset($data['line']) && is_int($data['line']) ? $data['line'] : null,
            label: isset($data['label']) && is_string($data['label']) ? $data['label'] : null,
            metadata: $metadata,
        );
    }
}
