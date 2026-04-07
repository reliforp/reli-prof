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

use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendConstant;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendConstantMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\GlobalConstantContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\GlobalConstantsContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ScalarValueContext;

/**
 * Emit the global_constants root branch.
 * Constant values are typically scalars/strings, so mostly leaf-like.
 * Object/array values are pushed as jobs for iterative processing.
 */
final class EmitGlobalConstantsJob implements CollectorJob
{
    public function __construct(
        private ZendArray $array,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $memory_location = ZendArrayTableMemoryLocation::fromZendArray($this->array);
        $ctx->memory_locations->add($memory_location);

        $global_constants_context = new GlobalConstantsContext($memory_location);

        foreach ($this->array->getItemIterator($ctx->dereferencer) as $constant_name => $zval) {
            assert(is_string($constant_name));
            $zend_constant = $ctx->dereferencer->deref(
                $zval->value->getAsPointer(
                    ZendConstant::class,
                    $ctx->zend_type_reader->sizeOf(ZendConstant::getCTypeName()),
                ),
            );
            $zend_constant_memory_location = ZendConstantMemoryLocation::fromZendConstant($zend_constant);
            $constant_context = new GlobalConstantContext($zend_constant_memory_location);
            $global_constants_context->add($constant_name, $constant_context);

            if (!is_null($zend_constant->name)) {
                $constant_name_context = CollectorHelpers::collectZendStringPointer(
                    $zend_constant->name,
                    $ctx,
                );
                $constant_context->add('name', $constant_name_context);
            }

            $value_zval = $zend_constant->value;
            if ($value_zval->isString() && !is_null($value_zval->value->str)) {
                $value_context = CollectorHelpers::collectZendStringPointer($value_zval->value->str, $ctx);
                $constant_context->add('value', $value_context);
            } elseif (
                $value_zval->isBool() || $value_zval->isLong()
                || $value_zval->isDouble() || $value_zval->isNull()
            ) {
                $scalar = match ($value_zval->getType()) {
                    'IS_TRUE' => new ScalarValueContext(true),
                    'IS_FALSE' => new ScalarValueContext(false),
                    'IS_LONG' => new ScalarValueContext($value_zval->value->lval),
                    'IS_DOUBLE' => new ScalarValueContext($value_zval->value->dval),
                    'IS_NULL' => new ScalarValueContext(null),
                    default => null,
                };
                if ($scalar !== null) {
                    $constant_context->add('value', $scalar);
                }
            }
            // Non-scalar constant values are rare; skip them to avoid recursion.
        }

        $ctx->emitNode($global_constants_context, null, 'global_constants');
    }
}
