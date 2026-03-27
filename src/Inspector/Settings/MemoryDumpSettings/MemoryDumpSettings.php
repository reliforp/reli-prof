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

namespace Reli\Inspector\Settings\MemoryDumpSettings;

final class MemoryDumpSettings
{
    public function __construct(
        public string $output_path,
        public bool $stop_process = true,
        public bool $include_binary = false,
    ) {
    }
}
