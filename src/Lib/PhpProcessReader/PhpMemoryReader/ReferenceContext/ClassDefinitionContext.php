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

final class ClassDefinitionContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public bool $is_internal,
    ) {
    }

    #[\Override]
    public function getContexts(): iterable
    {
        return ['#is_internal' => $this->is_internal];
    }

    #[\Override]
    public function getLinkStrength(string $link_name): EdgeStrength
    {
        return match ($link_name) {
            'class_entry' => EdgeStrength::Structural,
            default => EdgeStrength::Strong,
        };
    }

    public function getMethods(): ?DefinedFunctionsContext
    {
        /** @var DefinedFunctionsContext|null */
        return $this->referencing_contexts['methods'] ?? null;
    }
}
