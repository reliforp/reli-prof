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
use Reli\Lib\PhpInternals\Types\Zend\ZendWeakReference;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\WeakReferenceContext;

/**
 * Collect WeakReference internal referent.
 * Replaces collectWeakReference.
 */
final class EmitWeakReferenceJob implements CollectorJob
{
    private ZendWeakReference $zend_weak_reference;

    public function __construct(
        ZendObject $object,
        private ?int $object_node_id,
        CollectorContext $ctx,
    ) {
        $this->zend_weak_reference = $ctx->dereferencer->deref(
            ZendWeakReference::getPointerFromZendObjectPointer(
                $object->getPointer(),
                $ctx->zend_type_reader,
            ),
        );
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $weak_reference_context = new WeakReferenceContext();
        $node_id = $ctx->emitNode($weak_reference_context, $this->object_node_id, 'weak_reference');
        $parent = $node_id >= 0 ? $node_id : null;

        if ($this->zend_weak_reference->referent !== null) {
            try {
                $queue->push(new EmitObjectJob(
                    $this->zend_weak_reference->referent,
                    $parent,
                    'referent',
                ));
            } catch (\Throwable) {
            }
        }
    }
}
