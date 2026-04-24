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

use Reli\Lib\Log\Log;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendClassConstant;
use Reli\Lib\PhpInternals\Types\Zend\ZendClassEntry;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\DefaultPropertiesTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\DefaultStaticMembersTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\StaticMembersTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableOverheadMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendClassConstantMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendClassEntryMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendPropertyInfoMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassConstantContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassConstantInfoContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassConstantsContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassDefinitionContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassEntryContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassStaticPropertiesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\DefaultPropertiesTableContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\DefaultStaticPropertiesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\DefinedClassesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\PropertiesInfoContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\DefinedFunctionsContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\PropertyInfoContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ScalarValueContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Emit the class_table root branch.
 * Class definitions contain static properties and class constants whose values
 * can reference objects/arrays, but this happens through collectZval which is
 * now iterative. We collect class entries inline since the structure is mostly
 * leaf-like (strings, memory locations).
 *
 * Static property values and class constant values that reference objects/arrays
 * are pushed as ResolveZvalJob to the queue.
 */
final class EmitClassTableJob implements CollectorJob
{
    public function __construct(
        private ZendArray $array,
    ) {
    }

    /** @psalm-suppress MixedAssignment, MixedArgument, MixedArrayAccess */
    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $defined_classes_context = new DefinedClassesContext();

        // Emit the root node first
        $root_node_id = $ctx->emitNode($defined_classes_context, null, 'class_table');
        $parent = $root_node_id >= 0 ? $root_node_id : null;

        // Walk the class table by iterating arData directly with a
        // plain for-loop (no generators). Generator-based iterators
        // (getItemIterator, getBucketIterator) propagate a single
        // deref failure to the foreach and kill the entire walk.
        // A plain loop with per-bucket try-catch is resilient.
        $arData = $this->array->arData;
        if ($arData === null) {
            return;
        }
        $numUsed = $this->array->nNumUsed;
        for ($i = 0; $i < $numUsed; $i++) {
            try {
                $bucket = $ctx->dereferencer->deref($arData->indexedAt($i));
                if ($bucket->val->isUndef()) {
                    continue;
                }

                $pointer = $bucket->val->value->ce;
                if ($pointer === null) {
                    continue;
                }
                if ($ctx->memory_locations->has($pointer->address)) {
                    continue;
                }

                if ($bucket->key !== null) {
                    $zend_string = $ctx->dereferencer->deref($bucket->key);
                    $class_name = $zend_string->toString($ctx->dereferencer);
                } else {
                    $class_name = '?';
                }

                $class_entry = $ctx->dereferencer->deref($pointer);
                [$class_def_context, $deferred_zvals] = $this->collectClassDefinition($class_entry, $ctx, $queue);

                $ctx->emitNode($class_def_context, $parent, $class_name);
                $class_fallback_pid = $class_def_context->getMemoNodeId();

                /** @psalm-suppress ArgumentTypeCoercion */
                foreach ($deferred_zvals as [$parent_ctx, $link, $value]) {
                    $raw_pid = $parent_ctx->getMemoNodeId();
                    $pid = $raw_pid !== null
                        ? ($raw_pid < 0 ? -$raw_pid - 1 : $raw_pid)
                        : ($class_fallback_pid !== null && $class_fallback_pid >= 0 ? $class_fallback_pid : null);
                    if ($value instanceof \Reli\Lib\Process\Pointer\Pointer) {
                        $queue->push(new EmitArrayJob($value, $pid, $link));
                    } else {
                        $queue->push(new ResolveZvalJob($value, $pid, $link));
                    }
                }
            } catch (\Throwable) {
                // Per-bucket error isolation: a single unreadable class
                // must not abort the walk of remaining classes.
            }
        }
    }

    /**
     * @return array{ClassDefinitionContext, list<array{ReferenceContext, string, mixed}>}
     * @psalm-suppress MixedAssignment, MixedArgument, MixedArrayAccess, RedundantCastGivenDocblockType
     */
    private function collectClassDefinition(
        ZendClassEntry $class_entry,
        CollectorContext $ctx,
        JobQueue $queue,
    ): array {
        /** @var list<array{ReferenceContext, string, mixed}> */
        $deferred_zvals = [];
        $class_definition_context = new ClassDefinitionContext($class_entry->isInternal());
        $memory_location = ZendClassEntryMemoryLocation::fromZendClassEntry($class_entry);
        $ctx->memory_locations->add($memory_location);

        $ce_class_name = null;
        try {
            $ce_class_name = $class_entry->getClassName($ctx->dereferencer);
        } catch (\Throwable) {
            // Name read failures are non-fatal — the attribute is purely
            // for navigation hints.
        }

        $ce_filename = null;
        $ce_line_start = null;
        $ce_line_end = null;
        if (!$class_entry->isInternal() && !is_null($class_entry->info->user->filename)) {
            try {
                $ce_filename = $ctx->dereferencer
                    ->deref($class_entry->info->user->filename)
                    ->toString($ctx->dereferencer);
                $ce_line_start = $class_entry->info->user->line_start;
                $ce_line_end = $class_entry->info->user->line_end;
            } catch (\Throwable) {
                $ce_filename = null;
            }
        }

        $class_entry_context = new ClassEntryContext(
            $memory_location,
            $ce_class_name,
            $ce_filename,
            $ce_line_start,
            $ce_line_end,
        );
        $class_definition_context->add('class_entry', $class_entry_context);

        $class_name_context = CollectorHelpers::collectZendStringPointer(
            $class_entry->name,
            $ctx,
        );
        $class_definition_context->add('name', $class_name_context);

        if (!$class_entry->isInternal()) {
            if (!is_null($class_entry->info->user->filename)) {
                $file_name_context = CollectorHelpers::collectZendStringPointer(
                    $class_entry->info->user->filename,
                    $ctx,
                );
                $class_definition_context->add('filename', $file_name_context);
            }

            if (!is_null($doc_comment = $class_entry->getDocCommentPointer($ctx->zend_type_reader))) {
                $doc_comment_context = CollectorHelpers::collectZendStringPointer($doc_comment, $ctx);
                $class_definition_context->add('doc_comment', $doc_comment_context);
            }
        }

        // Static properties - values may contain zvals referencing objects/arrays
        if (
            $class_entry->default_static_members_count > 0
            and !is_null($class_entry->static_members_table)
        ) {
            $static_members_table_memory_location = StaticMembersTableMemoryLocation::fromZendClassEntry(
                $class_entry,
                $ctx->zend_type_reader,
                $ctx->dereferencer,
                $ctx->map_ptr_base,
            );
            $ctx->memory_locations->add($static_members_table_memory_location);
            $static_properties_context = new ClassStaticPropertiesContext(
                $static_members_table_memory_location,
            );
            $class_definition_context->add('static_properties', $static_properties_context);

            // Collect static property zvals — push to queue for iterative processing.
            // We need the static_properties node_id as parent, which will be assigned
            // when class_definition_context is emitted. Store zvals and push jobs
            // after the class definition is emitted (below).
            $static_property_iterator = $class_entry->getStaticPropertyIterator(
                $ctx->dereferencer,
                $ctx->zend_type_reader,
                $ctx->map_ptr_base,
            );
            foreach ($static_property_iterator as $name => $value) {
                $deferred_zvals[] = [$static_properties_context, (string)$name, $value];
            }
        }

        // Properties info
        $properties_info_context = $this->collectPropertiesInfo($class_entry, $ctx);
        $class_definition_context->add('property_info', $properties_info_context);

        // Default properties table
        if (!is_null($class_entry->default_properties_table)) {
            $default_properties_table_memory_location = DefaultPropertiesTableMemoryLocation::fromZendClassEntry(
                $class_entry,
            );
            $ctx->memory_locations->add($default_properties_table_memory_location);
            $default_properties_context = new DefaultPropertiesTableContext(
                $default_properties_table_memory_location,
            );
            $class_definition_context->add('default_properties', $default_properties_context);
        }

        // Default static members table
        if (!is_null($class_entry->default_static_members_table)) {
            $default_static_members_memory_location = DefaultStaticMembersTableMemoryLocation::fromZendClassEntry(
                $class_entry,
            );
            $ctx->memory_locations->add($default_static_members_memory_location);
            $default_static_properties_context = new DefaultStaticPropertiesContext(
                $default_static_members_memory_location,
            );
            $class_definition_context->add('default_static_properties', $default_static_properties_context);
        }

        // Methods (function table) — may have deferred static_variables
        $methods_array = $ctx->dereferencer->deref($class_entry->function_table->getPointer());
        [$methods_context, $method_results] = $this->collectFunctionTableInline($methods_array, $ctx);
        $class_definition_context->add('methods', $methods_context);
        foreach ($method_results as $r) {
            foreach ($r->deferred_arrays as [$arr_pointer, $arr_link, $parent_ctx]) {
                $deferred_zvals[] = [$parent_ctx, $arr_link, $arr_pointer];
            }
        }

        // Class constants (may have non-scalar values that need queue processing)
        $constants_array = $ctx->dereferencer->deref($class_entry->constants_table->getPointer());
        [$class_constants_context, $constant_deferred] = $this->collectClassConstantsTable($constants_array, $ctx);
        $class_definition_context->add('constants', $class_constants_context);
        foreach ($constant_deferred as $d) {
            $deferred_zvals[] = $d;
        }

        return [$class_definition_context, $deferred_zvals];
    }

    private function collectPropertiesInfo(
        ZendClassEntry $class_entry,
        CollectorContext $ctx,
    ): PropertiesInfoContext {
        $memory_location = ZendArrayTableMemoryLocation::fromZendArray($class_entry->properties_info);
        $ctx->memory_locations->add($memory_location);

        $properties_info_context = new PropertiesInfoContext($memory_location);

        $prop_iter = $class_entry->iteratePropertyInfo($ctx->dereferencer, $ctx->zend_type_reader);
        foreach ($prop_iter as $key => $property_info) {
            $property_info_memory_location = ZendPropertyInfoMemoryLocation::fromZendPropertyInfo($property_info);
            $ctx->memory_locations->add($property_info_memory_location);
            $property_info_context = new PropertyInfoContext($property_info_memory_location);
            if (!is_null($property_info->name)) {
                $property_info_name_context = CollectorHelpers::collectZendStringPointer(
                    $property_info->name,
                    $ctx,
                );
                $property_info_context->add('name', $property_info_name_context);
            }
            if (!is_null($property_info->doc_comment)) {
                $property_info_doc_comment_context = CollectorHelpers::collectZendStringPointer(
                    $property_info->doc_comment,
                    $ctx,
                );
                $property_info_context->add('doc_comment', $property_info_doc_comment_context);
            }
            $properties_info_context->add($key, $property_info_context);
        }
        return $properties_info_context;
    }

    /**
     * @return array{DefinedFunctionsContext, list<FunctionCollectionResult>}
     */
    private function collectFunctionTableInline(
        ZendArray $array,
        CollectorContext $ctx,
    ): array {
        $array_header_location = ZendArrayMemoryLocation::fromZendArray($array);
        $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($array);
        $array_table_overhead_location = ZendArrayTableOverheadMemoryLocation::fromZendArrayAndUsedLocation(
            $array,
            $array_table_location,
        );

        $ctx->memory_locations->add($array_header_location);
        $ctx->memory_locations->add($array_table_location);
        $ctx->memory_locations->add($array_table_overhead_location);

        $defined_functions_context = new DefinedFunctionsContext(
            $array_header_location,
            $array_table_location,
        );

        /** @var list<FunctionCollectionResult> */
        $deferred_results = [];
        foreach ($array->getItemIterator($ctx->dereferencer) as $function_name => $zval) {
            assert(is_string($function_name));
            assert(!is_null($zval->value->func));
            $result = CollectorHelpers::collectZendFunctionPointer($zval->value->func, $ctx);
            if (is_int($result)) {
                $defined_functions_context->add($function_name, $result);
            } else {
                $defined_functions_context->add($function_name, $result->context);
                if ($result->deferred_arrays !== []) {
                    $deferred_results[] = $result;
                }
            }
        }
        return [$defined_functions_context, $deferred_results];
    }

    /**
     * @return array{ClassConstantsContext, list<array{ReferenceContext, string, mixed}>}
     */
    private function collectClassConstantsTable(
        ZendArray $array,
        CollectorContext $ctx,
    ): array {
        /** @var list<array{ReferenceContext, string, mixed}> */
        $deferred = [];
        $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($array);
        $array_table_overhead_location = ZendArrayTableOverheadMemoryLocation::fromZendArrayAndUsedLocation(
            $array,
            $array_table_location,
        );
        $ctx->memory_locations->add($array_table_location);
        $ctx->memory_locations->add($array_table_overhead_location);
        $class_constants_context = new ClassConstantsContext($array_table_location);

        $array_iterator = $array->getItemIteratorWithZendStringKeyIfAssoc($ctx->dereferencer);
        foreach ($array_iterator as $constant_name => $zval_or_ptr) {
            assert($constant_name instanceof Pointer);
            $constant_name_context = CollectorHelpers::collectZendStringPointer($constant_name, $ctx);
            $zend_string = $ctx->dereferencer->deref($constant_name);
            $constant_name_string = $zend_string->toString($ctx->dereferencer);
            $constant_context = new ClassConstantContext();
            $class_constants_context->add($constant_name_string, $constant_context);
            $constant_context->add('name', $constant_name_context);

            if ($ctx->zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V71)) {
                $zval = $zval_or_ptr;
            } else {
                $class_constant_ptr = $zval_or_ptr->value->getAsPointer(
                    ZendClassConstant::class,
                    $ctx->zend_type_reader->sizeOf(ZendClassConstant::getCTypeName()),
                );
                $class_constant = $ctx->dereferencer->deref($class_constant_ptr);
                $memory_location = ZendClassConstantMemoryLocation::fromZendClassConstant($class_constant);
                $ctx->memory_locations->add($memory_location);
                $zval = $class_constant->value;
                $info_context = new ClassConstantInfoContext($memory_location);
                $constant_context->add('info', $info_context);
            }
            // Class constant values that are scalars/strings are handled inline.
            // Values that reference objects/arrays are handled by the analyzer
            // when the class_constants_context is emitted.
            // For now, we include the scalar value inline.
            if (
                $zval->isBool() || $zval->isLong() || $zval->isDouble()
                || $zval->isNull() || $zval->isString()
            ) {
                if ($zval->isString() && !is_null($zval->value->str)) {
                    $value_context = CollectorHelpers::collectZendStringPointer($zval->value->str, $ctx);
                    $constant_context->add('value', $value_context);
                } elseif ($zval->isBool() || $zval->isLong() || $zval->isDouble() || $zval->isNull()) {
                    $scalar = match ($zval->getType()) {
                        'IS_TRUE' => new ScalarValueContext(true),
                        'IS_FALSE' => new ScalarValueContext(false),
                        'IS_LONG' => new ScalarValueContext($zval->value->lval),
                        'IS_DOUBLE' => new ScalarValueContext($zval->value->dval),
                        'IS_NULL' => new ScalarValueContext(null),
                        default => null,
                    };
                    if ($scalar !== null) {
                        $constant_context->add('value', $scalar);
                    }
                }
            }
            // Non-scalar class constant values (arrays, objects) — defer to queue
            if ($zval->isArray() || $zval->isObject() || $zval->isReference()) {
                $deferred[] = [$constant_context, 'value', $zval];
            }
        }

        return [$class_constants_context, $deferred];
    }
}
