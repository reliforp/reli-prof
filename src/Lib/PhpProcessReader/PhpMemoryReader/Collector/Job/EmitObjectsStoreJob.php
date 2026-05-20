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
use Reli\Lib\PhpInternals\Types\Zend\ZendObjectsStore;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ObjectsStoreMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectsStoreContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Emit the objects_store root branch and push an iterator for buckets.
 * Replaces collectObjectsStore.
 */
final class EmitObjectsStoreJob implements CollectorJob
{
    public function __construct(
        private ZendObjectsStore $objects_store,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        \fwrite(\STDERR, "[reli-debug] EmitObjectsStoreJob::execute START\n");
        $objects_store_memory_location = ObjectsStoreMemoryLocation::fromZendObjectsStore(
            $this->objects_store,
        );
        $objects_store_context = new ObjectsStoreContext($objects_store_memory_location);
        $ctx->memory_locations->add($objects_store_memory_location);

        // Emit with weak edge strength at the root level
        $root_node_id = $ctx->emitNode(
            $objects_store_context,
            null,
            'objects_store',
            EdgeStrength::Weak,
        );
        $parent = $root_node_id >= 0 ? $root_node_id : null;

        assert($this->objects_store->object_buckets instanceof Pointer);
        $buckets = $ctx->dereferencer->deref($this->objects_store->object_buckets);
        $bucket_iterator = $buckets->getIteratorOfPointersTo(
            ZendObject::class,
            $ctx->zend_type_reader,
        );

        // Push the bucket iterator job
        $queue->push(new ObjectsStoreBucketIteratorJob(
            $bucket_iterator,
            $this->objects_store->top,
            $parent,
        ));
    }
}
