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

use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\GlobalCallbacksContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Emit the global_callbacks root branch.
 * Callbacks (error_handler, exception_handler) can reference closures/objects,
 * so their zval values are pushed as jobs.
 */
final class EmitGlobalCallbacksJob implements CollectorJob
{
    public function __construct(
        private ZendExecutorGlobals $eg,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        try {
            $global_callbacks_context = new GlobalCallbacksContext();

            // Emit the root node
            $root_node_id = $ctx->emitNode($global_callbacks_context, null, 'global_callbacks');
            $parent = $root_node_id >= 0 ? $root_node_id : null;

            // Push jobs for each callback field
            $this->pushCallbackJob('user_error_handler', 'error_handler', $parent, $ctx, $queue);
            $this->pushCallbackJob('user_exception_handler', 'exception_handler', $parent, $ctx, $queue);
        } catch (\Throwable $e) {
            \Reli\Lib\Log\Log::info('failed to collect global callbacks', ['error' => $e->getMessage()]);
        }
    }

    private function pushCallbackJob(
        string $eg_field_name,
        string $link_name,
        ?int $parent_node_id,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        [$offset,] = $ctx->zend_type_reader->getOffsetAndSizeOfMember(
            'zend_executor_globals',
            $eg_field_name,
        );
        $zval_size = $ctx->zend_type_reader->sizeOf('zval');
        $zval_pointer = new Pointer(
            Zval::class,
            $this->eg->getPointer()->address + $offset,
            $zval_size,
        );
        $zval = $ctx->dereferencer->deref($zval_pointer);
        if ($zval->isUndef()) {
            return;
        }

        $queue->push(new ResolveZvalJob(
            $zval,
            $parent_node_id,
            $link_name,
        ));
    }
}
