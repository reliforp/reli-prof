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
            $queue->push(new EmitArrayDirectJob(
                $this->zend_weak_map->ht,
                $parent,
                'entries',
            ));
        } catch (\Throwable) {
        }
    }
}
