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

use Reli\Lib\PhpInternals\Types\Zend\ZendClosure;
use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClosureContext;

/**
 * Collect closure internal data (func + this_ptr).
 * Replaces collectClosure. Called as a child job of EmitObjectJob.
 */
final class EmitClosureJob implements CollectorJob
{
    private ZendClosure $zend_closure;

    public function __construct(
        ZendObject $object,
        private ?int $object_node_id,
        CollectorContext $ctx,
    ) {
        $this->zend_closure = $ctx->dereferencer->deref(
            ZendClosure::getPointerFromZendObjectPointer(
                $object->getPointer(),
                $ctx->zend_type_reader,
            ),
        );
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $closure_address = $this->zend_closure->getPointer()->address;
        $cached = $ctx->context_pools->closure_context_pool->getContextForAddress($closure_address);
        if ($cached !== null) {
            $ctx->emitNode($cached, $this->object_node_id, 'closure');
            return;
        }

        $closure_context = new ClosureContext();
        $ctx->context_pools->closure_context_pool->register($closure_address, $closure_context);

        $func_result = CollectorHelpers::collectZendFunction(
            $ctx->dereferencer->deref($this->zend_closure->func->getPointer()),
            $ctx,
        );
        $closure_context->add('func', $func_result->context);

        $closure_node_id = $ctx->emitNode($closure_context, $this->object_node_id, 'closure');

        // Push deferred static_variables arrays.
        // See EmitObjectJob for why this reads from getMemoNodeId().
        foreach ($func_result->deferred_arrays as [$arr_pointer, $arr_link, $parent_ctx]) {
            $raw_parent_id = $parent_ctx->getMemoNodeId();
            $parent_id = $raw_parent_id === null
                ? null
                : ($raw_parent_id < 0 ? -$raw_parent_id - 1 : $raw_parent_id);
            /** @psalm-suppress ArgumentTypeCoercion */
            $queue->push(new EmitArrayJob($arr_pointer, $parent_id, $arr_link));
        }

        // Push job for this_ptr (may reference objects = recursive)
        $queue->push(new ResolveZvalJob(
            $this->zend_closure->this_ptr,
            $closure_node_id >= 0 ? $closure_node_id : null,
            'this_ptr',
        ));
    }
}
