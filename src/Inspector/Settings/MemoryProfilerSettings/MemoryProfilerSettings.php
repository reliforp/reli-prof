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

namespace Reli\Inspector\Settings\MemoryProfilerSettings;

final class MemoryProfilerSettings
{
    public function __construct(
        public bool $stop_process,
        public bool $pretty_print,
        public ?MemoryLimitErrorDetails $memory_exhaustion_error_details = null,
        public string $output_format = 'json',
        public ?string $output_path = null,
        public string $db_host = '127.0.0.1',
        public ?int $db_port = null,
        public ?string $db_name = null,
        public ?string $db_user = null,
        public ?string $db_password = null,
    ) {
    }
}
