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

use Reli\Lib\Process\MemoryLocation;

/** @psalm-require-implements ReferenceContext */
trait ReferenceContextDefault
{
    /** @var array<string, ReferenceContext> */
    private array $referencing_contexts = [];

    public function getName(): string
    {
        return (new \ReflectionClass(static::class))->getShortName();
    }

    public function add(string $link_name, ReferenceContext $reference_context): void
    {
        $this->referencing_contexts[$link_name] = $reference_context;
    }

    /** @return array<string, ReferenceContext> */
    public function getLinks(): iterable
    {
        return $this->referencing_contexts;
    }

    /** @return iterable<array-key, MemoryLocation> */
    public function getLocations(): iterable
    {
        return [];
    }

    public function getContexts(): iterable
    {
        return [];
    }
}
