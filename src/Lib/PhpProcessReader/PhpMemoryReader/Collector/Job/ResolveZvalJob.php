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

use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ScalarValueContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Dispatch job that reads a zval's type and pushes the appropriate emit job.
 * Replaces the recursive collectZval method.
 */
final class ResolveZvalJob implements CollectorJob
{
    public function __construct(
        private Zval $zval,
        private ?int $parent_node_id,
        private string $link_name,
        private EdgeStrength $edge_strength = EdgeStrength::Strong,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $zval = $this->zval;

        if ($zval->isArray()) {
            assert(!is_null($zval->value->arr));
            $queue->push(new EmitArrayJob(
                $zval->value->arr,
                $this->parent_node_id,
                $this->link_name,
                $this->edge_strength,
            ));
        } elseif ($zval->isObject()) {
            if ($zval->value->obj === null) {
                return;
            }
            $queue->push(new EmitObjectJob(
                $zval->value->obj,
                $this->parent_node_id,
                $this->link_name,
                $this->edge_strength,
            ));
        } elseif ($zval->isString()) {
            assert(!is_null($zval->value->str));
            $queue->push(new EmitStringJob(
                $zval->value->str,
                $this->parent_node_id,
                $this->link_name,
                $this->edge_strength,
            ));
        } elseif (
            $zval->isBool()
            or $zval->isLong()
            or $zval->isDouble()
            or $zval->isNull()
        ) {
            $scalar = match ($zval->getType()) {
                'IS_TRUE' => new ScalarValueContext(true),
                'IS_FALSE' => new ScalarValueContext(false),
                'IS_LONG' => new ScalarValueContext($zval->value->lval),
                'IS_DOUBLE' => new ScalarValueContext($zval->value->dval),
                'IS_NULL' => new ScalarValueContext(null),
            };
            if ($this->parent_node_id !== null) {
                $ctx->emitNode($scalar, $this->parent_node_id, $this->link_name, $this->edge_strength);
            }
        } elseif ($zval->isReference()) {
            assert(!is_null($zval->value->ref));
            $queue->push(new EmitPhpReferenceJob(
                $zval->value->ref,
                $this->parent_node_id,
                $this->link_name,
                $this->edge_strength,
            ));
        } elseif ($zval->isResource()) {
            assert(!is_null($zval->value->res));
            $queue->push(new EmitResourceJob(
                $zval->value->res,
                $this->parent_node_id,
                $this->link_name,
                $this->edge_strength,
            ));
        } elseif ($zval->isIndirect()) {
            $inner_zval = $ctx->dereferencer->deref(
                $zval->value->getAsPointer(Zval::class, $ctx->zend_type_reader->sizeOf('zval'))
            );
            $queue->push(new self(
                $inner_zval,
                $this->parent_node_id,
                $this->link_name,
                $this->edge_strength,
            ));
        }
    }
}
