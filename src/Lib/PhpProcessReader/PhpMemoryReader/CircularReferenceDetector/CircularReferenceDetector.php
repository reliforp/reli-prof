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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\CircularReferenceDetector;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\Process\MemoryLocation;
use WeakMap;

final class CircularReferenceDetector
{
    /** @var list<CircularReference> */
    private array $found_cycles = [];

    /** @var WeakMap<ReferenceContext, true> */
    private WeakMap $visited;

    /** @var WeakMap<ReferenceContext, true> */
    private WeakMap $on_stack;

    /** @var list<array{link_name: string, context: ReferenceContext}> */
    private array $current_path = [];

    /** @var WeakMap<ReferenceContext, true> */
    private WeakMap $cycle_reported;

    public function detect(ReferenceContext $root): CircularReferenceDetectorResult
    {
        $this->found_cycles = [];
        /** @var WeakMap<ReferenceContext, true> */
        $this->visited = new WeakMap();
        /** @var WeakMap<ReferenceContext, true> */
        $this->on_stack = new WeakMap();
        $this->current_path = [];
        /** @var WeakMap<ReferenceContext, true> */
        $this->cycle_reported = new WeakMap();

        $this->dfs($root, '(root)');

        return new CircularReferenceDetectorResult($this->found_cycles);
    }

    private function dfs(ReferenceContext $context, string $link_name): void
    {
        if (isset($this->on_stack[$context])) {
            $this->recordCycle($context);
            return;
        }

        if (isset($this->visited[$context])) {
            return;
        }

        $this->visited[$context] = true;
        $this->on_stack[$context] = true;
        $this->current_path[] = ['link_name' => $link_name, 'context' => $context];

        foreach ($context->getLinks() as $child_link_name => $child_context) {
            $this->dfs($child_context, (string)$child_link_name);
        }

        array_pop($this->current_path);
        unset($this->on_stack[$context]);
    }

    private function recordCycle(ReferenceContext $cycle_target): void
    {
        if (isset($this->cycle_reported[$cycle_target])) {
            return;
        }

        $cycle_path = [];
        $found_start = false;
        foreach ($this->current_path as $entry) {
            if ($entry['context'] === $cycle_target) {
                $found_start = true;
            }
            if ($found_start) {
                $cycle_path[] = $this->createNode($entry['context'], $entry['link_name']);
            }
        }

        if ($cycle_path !== []) {
            $this->found_cycles[] = new CircularReference($cycle_path);
            $this->cycle_reported[$cycle_target] = true;
        }
    }

    private function createNode(ReferenceContext $context, string $link_name): CircularReferenceNode
    {
        $locations = $context->getLocations();
        if (!is_array($locations)) {
            $locations = iterator_to_array($locations);
        }

        $address = 0;
        $size = 0;
        $class_name = null;

        if ($locations !== []) {
            /** @var MemoryLocation $first_location */
            $first_location = $locations[0];
            $address = $first_location->address;
            $size = $first_location->size;
            if ($first_location instanceof ZendObjectMemoryLocation) {
                $class_name = $first_location->class_name;
            }
        }

        return new CircularReferenceNode(
            context_type: $context->getName(),
            link_name: $link_name,
            address: $address,
            size: $size,
            class_name: $class_name,
        );
    }
}
