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

use Reli\Lib\PhpInternals\Types\Zend\ZendFunction;
use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\DynamicFuncDefsTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\LocalVariableNameTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\RuntimeCacheMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArgInfosMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendOpArrayBodyMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendOpArrayHeaderMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArgInfoContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArgInfosContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\DynamicFuncDefsContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\FunctionDefinitionContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\InternalFunctionDefinitionContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\LocalVariableNameTableContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\OpArrayContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\RuntimeCacheContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\StringContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\UserFunctionDefinitionContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Non-recursive helper methods shared across job classes.
 * These methods do NOT participate in the recursive collect chain.
 */
final class CollectorHelpers
{
    /**
     * Collect a ZendString and return its context.
     * Does not push any jobs (leaf operation).
     *
     * @param Pointer<ZendString> $pointer
     */
    public static function collectZendStringPointer(
        Pointer $pointer,
        CollectorContext $ctx,
    ): StringContext|int {
        $address = $pointer->address;
        if ($ctx->memory_locations->has($address)) {
            $existing_node_id = $ctx->address_map[$address] ?? null;
            if ($existing_node_id !== null) {
                return $existing_node_id;
            }
            $cached = $ctx->context_pools->string_context_pool->getContextByAddress($address);
            if ($cached !== null) {
                return $cached;
            }
        }
        $str = $ctx->dereferencer->deref($pointer);
        $memory_location = ZendStringMemoryLocation::fromZendString($str, $ctx->dereferencer);
        $ctx->memory_locations->add($memory_location);
        return $ctx->context_pools->string_context_pool->getContextForLocation($memory_location);
    }

    /**
     * Collect a ZendFunction (user or internal). Does not push recursive jobs.
     * Returns a FunctionDefinitionContext. This handles the full user function
     * definition including op_array, arg_infos, etc.
     *
     * Note: static_variables and dynamic_func_defs within user functions CAN
     * contain zvals that reference objects/arrays. These are collected inline
     * here (non-recursively) to match the original behavior. Since these are
     * typically small and don't form deep chains, the stack depth is bounded.
     */
    /**
     */
    public static function collectZendFunction(
        ZendFunction $func,
        CollectorContext $ctx,
    ): FunctionCollectionResult {
        if ($func->isUserFunction()) {
            $result = self::collectUserFunctionDefinition($func, $ctx);
        } else {
            $result = new FunctionCollectionResult(new InternalFunctionDefinitionContext());
        }
        if (!is_null($func->function_name)) {
            $function_name_context = self::collectZendStringPointer(
                $func->function_name,
                $ctx,
            );
            $result->context->add('name', $function_name_context);
        }
        return $result;
    }

    /**
     * @param Pointer<ZendFunction> $pointer
     */
    /**
     * @param Pointer<ZendFunction> $pointer
     */
    public static function collectZendFunctionPointer(
        Pointer $pointer,
        CollectorContext $ctx,
    ): FunctionCollectionResult|int {
        $address = $pointer->address;
        if ($ctx->memory_locations->has($address)) {
            $existing_node_id = $ctx->address_map[$address] ?? null;
            if ($existing_node_id !== null) {
                return $existing_node_id;
            }
            $cached = $ctx->context_pools->user_function_definition_context_pool->getContextByAddress($address);
            if ($cached !== null) {
                return new FunctionCollectionResult($cached);
            }
        }
        $func = $ctx->dereferencer->deref($pointer);
        return self::collectZendFunction($func, $ctx);
    }

    /**
     */
    private static function collectUserFunctionDefinition(
        ZendFunction $func,
        CollectorContext $ctx,
    ): FunctionCollectionResult {
        /** @var list<array{\Reli\Lib\Process\Pointer\Pointer, string}> */
        $deferred_arrays = [];
        $function_name = $func->getFullyQualifiedFunctionName(
            $ctx->dereferencer,
            $ctx->zend_type_reader,
        );
        $op_array_header_memory_location = ZendOpArrayHeaderMemoryLocation::fromZendFunction(
            $func,
            $ctx->zend_type_reader,
            $ctx->dereferencer,
        );
        $op_array_body_memory_location = ZendOpArrayBodyMemoryLocation::fromZendFunction(
            $func,
            $ctx->zend_type_reader,
            $function_name,
        );
        $ctx->memory_locations->add($op_array_header_memory_location);
        $ctx->memory_locations->add($op_array_body_memory_location);
        $function_definition_context = $ctx->context_pools
            ->user_function_definition_context_pool
            ->getContextForLocation($op_array_header_memory_location);

        // Check if this function_definition_context already has an op_array
        // from a previous emit (pool cache hit). If so, reuse that context
        // so deferred_arrays reference the already-emitted OpArrayContext
        // (which has its memo set). Creating a new one would leave its memo
        // null, causing static_variables to land on fallback parent.
        $existing_op_array = $function_definition_context->getLinks()['op_array'] ?? null;
        if ($existing_op_array instanceof OpArrayContext && $existing_op_array->getMemoNodeId() !== null) {
            $op_array_context = $existing_op_array;
        } else {
            $op_array_context = new OpArrayContext(
                $op_array_header_memory_location,
                $op_array_body_memory_location,
            );
            $function_definition_context->add('op_array', $op_array_context);
        }

        if ($func->op_array->cache_size > 0) {
            $runtime_cache_memory_location = RuntimeCacheMemoryLocation::fromZendOpArray(
                $func->op_array,
                $ctx->dereferencer,
                $ctx->zend_type_reader,
                $ctx->map_ptr_base,
            );
            if ($runtime_cache_memory_location->address !== 0) {
                $ctx->memory_locations->add($runtime_cache_memory_location);
                $run_time_cache_context = new RuntimeCacheContext($runtime_cache_memory_location);
                $op_array_context->add('run_time_cache', $run_time_cache_context);
            }
        }

        if (!is_null($func->op_array->arg_info)) {
            $arginfos_memory_location = ZendArgInfosMemoryLocation::fromZendOpArray(
                $func->op_array,
                $ctx->zend_type_reader,
            );
            $ctx->memory_locations->add($arginfos_memory_location);
            $arginfos_context = new ArgInfosContext($arginfos_memory_location);
            $op_array_context->add('arg_infos', $arginfos_context);
            foreach ($func->op_array->iterateArgInfo($ctx->dereferencer, $ctx->zend_type_reader) as $arg_info) {
                if (!is_null($arg_info->name)) {
                    $arg_info_context = new ArgInfoContext();
                    $arg_info_name_context = self::collectZendStringPointer(
                        $arg_info->name,
                        $ctx,
                    );
                    $arg_info_name = $ctx->dereferencer
                        ->deref($arg_info->name)
                        ->toString($ctx->dereferencer);
                    $arg_info_context->add('name', $arg_info_name_context);
                    $arginfos_context->add($arg_info_name, $arg_info_context);
                }
            }
        }

        if (!is_null($func->op_array->doc_comment)) {
            $doc_comment_context = self::collectZendStringPointer(
                $func->op_array->doc_comment,
                $ctx,
            );
            $op_array_context->add('doc_comment', $doc_comment_context);
        }

        if (!is_null($func->op_array->filename)) {
            $file_name_context = self::collectZendStringPointer(
                $func->op_array->filename,
                $ctx,
            );
            $op_array_context->add('filename', $file_name_context);
        }

        // Note: static_variables and dynamic_func_defs are handled inline here.
        // These don't participate in the deep recursive chain (they're typically small).
        // We keep them as direct calls to avoid complexity.
        if (!is_null($func->op_array->static_variables)) {
            // Defer static_variables array processing — it may contain
            // object/array references that need the job queue.
            // The caller will push EmitArrayJob after emitting this context.
            $deferred_arrays[] = [$func->op_array->static_variables, 'static_variables', $op_array_context];
        }

        if (!is_null($func->op_array->vars)) {
            $local_variable_name_table_location = LocalVariableNameTableMemoryLocation::fromZendOpArray(
                $func->op_array,
            );
            $ctx->memory_locations->add($local_variable_name_table_location);
            $variable_name_table_context = new LocalVariableNameTableContext(
                $local_variable_name_table_location,
            );
            $op_array_context->add('variable_name_table', $variable_name_table_context);

            $variable_names_iterator = $func->op_array->getVariableNamesAsIteratorOfPointersToZendStrings(
                $ctx->dereferencer,
                $ctx->zend_type_reader,
            );
            foreach ($variable_names_iterator as $key => $variable_name) {
                $variable_name_context = self::collectZendStringPointer(
                    $variable_name,
                    $ctx,
                );
                $variable_name_table_context->add((string)$key, $variable_name_context);
            }
        }

        if ($func->op_array->num_dynamic_func_defs > 0) {
            $dynamic_func_defs_table_memory_location = DynamicFuncDefsTableMemoryLocation::fromZendOpArray(
                $func->op_array,
            );
            $ctx->memory_locations->add($dynamic_func_defs_table_memory_location);
            $dynamic_func_defs_context = new DynamicFuncDefsContext(
                $dynamic_func_defs_table_memory_location,
            );
            $dynamic_func_defs_iterator = $func->op_array->iterateDynamicFunctionDefinitions(
                $ctx->dereferencer,
                $ctx->zend_type_reader,
            );
            foreach ($dynamic_func_defs_iterator as $key => $dynamic_func_def) {
                $result = self::collectZendFunctionPointer($dynamic_func_def, $ctx);
                if (is_int($result)) {
                    $dynamic_func_defs_context->add((string)$key, $result);
                } else {
                    $dynamic_func_defs_context->add((string)$key, $result->context);
                }
            }
            $op_array_context->add('dynamic_function_definitions', $dynamic_func_defs_context);
        }

        if (!is_null($ctx->memory_limit_error_details)) {
            if (
                $function_definition_context->isThisContext(
                    $ctx->memory_limit_error_details->file,
                    $ctx->memory_limit_error_details->line,
                )
            ) {
                if (
                    is_null($ctx->memory_limit_error_function_context)
                    or $function_definition_context->isClosureOf(
                        $ctx->memory_limit_error_function_context,
                    )
                ) {
                    $ctx->memory_limit_error_function_context = $function_definition_context;
                }
            }
        }

        /** @psalm-suppress ArgumentTypeCoercion -- Pointer<ZendArray> from op_array->static_variables */
        return new FunctionCollectionResult($function_definition_context, $deferred_arrays);
    }
}
