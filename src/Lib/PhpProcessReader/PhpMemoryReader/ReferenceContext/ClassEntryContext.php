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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendClassEntryMemoryLocation;

final class ClassEntryContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public ZendClassEntryMemoryLocation $memory_location,
        public readonly ?string $class_name = null,
        public readonly ?string $filename = null,
        public readonly ?int $line_start = null,
        public readonly ?int $line_end = null,
    ) {
    }

    #[\Override]
    public function getLocations(): array
    {
        return [$this->memory_location];
    }

    #[\Override]
    public function getContexts(): iterable
    {
        $attrs = [];
        if ($this->class_name !== null && $this->class_name !== '') {
            $attrs['class_name'] = $this->class_name;
        }
        if ($this->filename !== null && $this->filename !== '') {
            $attrs['filename'] = $this->filename;
        }
        if ($this->line_start !== null && $this->line_start > 0) {
            $attrs['line_start'] = $this->line_start;
        }
        if ($this->line_end !== null && $this->line_end > 0) {
            $attrs['line_end'] = $this->line_end;
        }
        return $attrs;
    }
}
