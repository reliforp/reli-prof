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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\Job;

use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpInternals\Types\Zend\ZendWeakMap;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableOverheadMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayElementsContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayPossibleOverheadContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\InlineArrayHeaderContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\WeakMapContext;

/**
 * Collect WeakMap internal hash table.
 * Replaces collectWeakMap.
 */
final class EmitWeakMapJob implements CollectorJob
{
    private ZendWeakMap $zend_weak_map;

    public function __construct(
        ZendObject $object,
        private ?int $object_node_id,
        CollectorContext $ctx,
    ) {
        $this->zend_weak_map = $ctx->dereferencer->deref(
            ZendWeakMap::getPointerFromZendObjectPointer(
                $object->getPointer(),
                $ctx->zend_type_reader,
            ),
        );
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $weak_map_context = new WeakMapContext();
        $node_id = $ctx->emitNode($weak_map_context, $this->object_node_id, 'weak_map');
        $parent = $node_id >= 0 ? $node_id : null;

        try {
            $array = $this->zend_weak_map->ht;
        } catch (\Throwable) {
            return;
        }

        // The inner HashTable header is INLINE in zend_weakmap
        // (`{ HashTable ht; zend_object std; }` with `ht` at offset 0), so its
        // bytes already belong to the parent ZendObjectMemoryLocation
        // (sizeof(zend_weakmap) ≈ 96 B includes the 56 B ht). Use
        // InlineArrayHeaderContext — same structural shape as
        // ArrayHeaderContext but without a ZendArrayMemoryLocation — so the
        // header doesn't (a) double-count those 56 B nor (b) collide with the
        // WeakMap object on `(address)` keying inside
        // `PdoMemoryOutput::computeCanonicalNodeIds` / the binary canonical
        // map, which used to close (object → weak_map → entries) into a
        // phantom 1x WeakMap cycle_cluster.
        //
        // The buckets table (`arData`) is a separate heap allocation and is
        // tracked normally.
        $entries_context = new InlineArrayHeaderContext();
        $array_elements_context = null;
        if (!is_null($array->arData) && !$array->isUninitialized()) {
            $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($array);
            $array_table_overhead_location =
                ZendArrayTableOverheadMemoryLocation::fromZendArrayAndUsedLocation(
                    $array,
                    $array_table_location,
                );
            $ctx->memory_locations->add($array_table_location);
            $ctx->memory_locations->add($array_table_overhead_location);

            $array_elements_context = new ArrayElementsContext($array_table_location);
            $array_elements_context->setCount($array->nNumOfElements);
            $overhead_context = new ArrayPossibleOverheadContext($array_table_overhead_location);

            $entries_context->add('array_elements', $array_elements_context);
            $entries_context->add('possible_unused_area', $overhead_context);
        }

        $entries_node_id = $ctx->emitNode($entries_context, $parent, 'entries');

        if ($array_elements_context !== null) {
            $raw_elements = $array_elements_context->getMemoNodeId();
            $elements_node_id = $raw_elements !== null
                ? ($raw_elements < 0 ? -$raw_elements - 1 : $raw_elements)
                : ($entries_node_id >= 0 ? $entries_node_id : null);
            try {
                $queue->push(new ArrayElementsIteratorJob(
                    $array,
                    $elements_node_id,
                ));
            } catch (\Throwable) {
            }
        }
    }
}
