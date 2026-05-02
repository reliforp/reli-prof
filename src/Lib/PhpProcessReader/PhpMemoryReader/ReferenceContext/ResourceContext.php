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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendResourceMemoryLocation;

final class ResourceContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public ?string $stream_type_label = null;
    public ?int $stream_fd = null;
    public ?string $stream_orig_path = null;

    public function __construct(
        public ZendResourceMemoryLocation $memory_location,
    ) {
    }

    #[\Override]
    public function getLocations(): iterable
    {
        yield $this->memory_location;
        yield from $this->extra_locations;
    }

    #[\Override]
    public function getContexts(): iterable
    {
        $contexts = [];
        if ($this->stream_type_label !== null) {
            $contexts['stream_type_label'] = $this->stream_type_label;
        }
        if ($this->stream_fd !== null) {
            $contexts['stream_fd'] = $this->stream_fd;
        }
        if ($this->stream_orig_path !== null) {
            $contexts['stream_orig_path'] = $this->stream_orig_path;
        }
        return $contexts;
    }
}
