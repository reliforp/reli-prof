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
    /** @var array<string, ReferenceContext|int> */
    private array $referencing_contexts = [];

    /** @var list<MemoryLocation> */
    private array $extra_locations = [];

    public function getName(): string
    {
        return (new \ReflectionClass(static::class))->getShortName();
    }

    public function add(string $link_name, ReferenceContext|int $reference_context): void
    {
        $this->referencing_contexts[$link_name] = $reference_context;
    }

    public function addLocation(MemoryLocation $location): void
    {
        $this->extra_locations[] = $location;
    }

    /** @return array<string, ReferenceContext|int> */
    public function getLinks(): iterable
    {
        return $this->referencing_contexts;
    }

    /** @return iterable<array-key, MemoryLocation> */
    public function getLocations(): iterable
    {
        return $this->extra_locations;
    }

    public function getContexts(): iterable
    {
        return [];
    }

    public function getLinkStrength(string $link_name): EdgeStrength
    {
        return EdgeStrength::Strong;
    }

    public function releaseLinks(): void
    {
        $this->referencing_contexts = [];
    }
}
