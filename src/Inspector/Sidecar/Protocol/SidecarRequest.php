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
    public function __construct(
        public readonly string $command,
        public readonly int $pid,
        public readonly ?string $error_file = null,
        public readonly ?int $error_line = null,
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
        return new self(
            command: $command,
            pid: $pid,
            error_file: isset($data['file']) && is_string($data['file']) ? $data['file'] : null,
            error_line: isset($data['line']) && is_int($data['line']) ? $data['line'] : null,
        );
    }
}
