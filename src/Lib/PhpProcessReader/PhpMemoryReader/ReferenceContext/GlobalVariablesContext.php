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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;

class GlobalVariablesContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public ZendArrayMemoryLocation $memory_location,
    ) {
    }

    public static function fromArrayContext(
        ArrayHeaderContext $array_context
    ): self {
        $self = new self(
            $array_context->memory_location
        );
        $self->referencing_contexts = $array_context->getLinks();
        return $self;
    }
}
