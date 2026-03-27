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

namespace Reli\Inspector\Settings\GetTraceSettings;

final class GetTraceSettings
{
    public const BULK_STACK_COPY_DEFAULT_MAX_SIZE = 65536;

    /**
     * @param int|null $bulk_stack_copy_max_size null = disabled, int = max bytes to prefetch
     */
    public function __construct(
        public int $depth,
        public bool $with_native_trace = false,
        public bool $native_trace_anytime = false,
        public ?int $bulk_stack_copy_max_size = null,
    ) {
    }
}
