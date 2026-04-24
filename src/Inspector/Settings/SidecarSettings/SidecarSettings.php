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

namespace Reli\Inspector\Settings\SidecarSettings;

/** @psalm-immutable */
final class SidecarSettings
{
    /**
     * @param array<string, string> $tags session-level tags applied to every snapshot
     */
    public function __construct(
        public string $socket_path,
        public string $output_dir = '.',
        public int $disk_usage_limit_bytes = 1073741824, // 1GB
        public bool $include_binary = false,
        public ?string $memory_limit = null,
        public array $tags = [],
    ) {
    }
}
