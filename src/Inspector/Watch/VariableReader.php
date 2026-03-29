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

namespace Reli\Inspector\Watch;

use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Inspector\Watch\Trigger\VariableValueTrigger;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendClassEntry;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendCompilerGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendFunction;
use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Reads PHP variable values from a target process.
 *
 * Supported scopes:
 * - global:      EG->symbol_table
 * - local:       current call frame CVs
 * - static:      class static properties (requires CG)
 * - func_static: function static variables
 */
final class VariableReader
{
    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
    ) {
    }

    /**
     * @param list<VariableValueTrigger> $triggers
     * @param TargetPhpSettings<'v70'|'v71'|'v72'|'v73'|'v74'|'v80'|'v81'|'v82'|'v83'|'v84'|'v85'> $target_php_settings
     * @return array<string, VariableValue>
     */
    public function readVariables(
        array $triggers,
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        int $cg_address = 0,
    ): array {
        if (count($triggers) === 0) {
            return [];
        }

        $php_version = $target_php_settings->php_version;
        $dereferencer = $this->getDereferencer(
            $process_specifier,
            $php_version,
        );
        $zend_type_reader = $this->zend_type_reader_creator->create(
            $php_version,
        );

        $eg_pointer = new Pointer(
            ZendExecutorGlobals::class,
            $eg_address,
            $zend_type_reader->sizeOf('zend_executor_globals'),
        );
        $eg = $dereferencer->deref($eg_pointer);

        $results = [];

        foreach ($triggers as $trigger) {
            $key = $trigger->lookup_key;
            $scope = $trigger->scope;
            $name = $trigger->var_name;

            try {
                $value = match ($scope) {
                    'global' => $this->readGlobalVariable(
                        $eg,
                        $dereferencer,
                        $zend_type_reader,
                        $name,
                    ),
                    'local' => $this->readLocalVariable(
                        $eg,
                        $dereferencer,
                        $zend_type_reader,
                        $name,
                    ),
                    'static' => $this->readStaticProperty(
                        $eg,
                        $dereferencer,
                        $zend_type_reader,
                        $name,
                        $cg_address,
                    ),
                    'func_static' => $this->readFuncStaticVariable(
                        $eg,
                        $dereferencer,
                        $zend_type_reader,
                        $name,
                        $cg_address,
                    ),
                    default => null,
                };
                if ($value !== null) {
                    $results[$key] = $value;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $results;
    }

    private function readGlobalVariable(
        ZendExecutorGlobals $eg,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        string $name,
    ): ?VariableValue {
        // Strip leading $ if present
        $name = str_starts_with($name, '$') ? substr($name, 1) : $name;
        [$root, $path_segments] = self::parsePathExpression($name);
        $bucket = $eg->symbol_table->findByKey($dereferencer, $root);
        if ($bucket === null) {
            return null;
        }
        // Re-deref for independent CData (avoid dangling view)
        $root_zval = $dereferencer->deref(
            $bucket->val->getPointer(),
        );
        $zval = $this->resolvePath(
            $root_zval,
            $path_segments,
            $dereferencer,
            $zend_type_reader,
        );
        if ($zval === null) {
            return null;
        }
        return $this->zvalToVariableValue($zval, $dereferencer);
    }

    /**
     * Read a local variable by walking up the call stack.
     *
     * Name format:
     *   $var                            — walk stack, return first match
     *   App\Service::process()$var      — only look in that function's frame
     *   main()$var                      — only look in the main script frame
     *
     * () marks the function name, $ marks the variable start.
     */
    private function readLocalVariable(
        ZendExecutorGlobals $eg,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        string $name,
    ): ?VariableValue {
        [$func_filter, $var_part] = self::parseLocalExpression($name);
        [$root, $path_segments] = self::parsePathExpression($var_part);
        $frame_pointer = $eg->current_execute_data;

        while ($frame_pointer !== null) {
            $execute_data = $dereferencer->deref($frame_pointer);

            // If function filter is set, check frame matches
            if ($func_filter !== null) {
                $matches = $this->frameMatchesFunction(
                    $execute_data,
                    $dereferencer,
                    $zend_type_reader,
                    $func_filter,
                );
                if (!$matches) {
                    $frame_pointer = $execute_data->prev_execute_data;
                    continue;
                }
            }

            foreach (
                $execute_data->getVariables(
                    $dereferencer,
                    $zend_type_reader,
                ) as $var_name => $zval
            ) {
                if ($var_name === $root) {
                    $resolved = $this->resolvePath(
                        $zval,
                        $path_segments,
                        $dereferencer,
                        $zend_type_reader,
                    );
                    if ($resolved === null) {
                        return null;
                    }
                    return $this->zvalToVariableValue(
                        $resolved,
                        $dereferencer,
                    );
                }
            }

            // If function filter matched but var not found, stop
            if ($func_filter !== null) {
                return null;
            }

            $frame_pointer = $execute_data->prev_execute_data;
        }

        return null;
    }

    /**
     * Parse local expression into optional function filter + variable part.
     *
     * "App\Svc::process()$retries"    → ["App\Svc::process", "retries"]
     * "handleRequest()$items"          → ["handleRequest", "items"]
     * "main()$counter"                 → ["main", "counter"]
     * "$counter"                       → [null, "counter"]
     * "counter"                        → [null, "counter"]
     *
     * () marks the function, $ marks the variable start.
     *
     * @return array{?string, string} [function_name, var_name_with_path]
     */
    public static function parseLocalExpression(string $name): array
    {
        // Look for ()$ pattern — function()$variable
        $marker = ')$';
        $pos = strpos($name, $marker);
        if ($pos !== false) {
            // Find the matching ( before )
            $paren_open = strrpos(
                substr($name, 0, $pos + 1),
                '(',
            );
            if ($paren_open !== false) {
                $func = substr($name, 0, $paren_open);
                $var = substr($name, $pos + strlen($marker));
                return [$func, $var];
            }
        }

        // No function filter — strip leading $ if present
        if (str_starts_with($name, '$')) {
            return [null, substr($name, 1)];
        }

        return [null, $name];
    }

    /**
     * Check if an execute_data frame matches the given function name.
     */
    private function frameMatchesFunction(
        \Reli\Lib\PhpInternals\Types\Zend\ZendExecuteData $execute_data,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        string $function_name,
    ): bool {
        if ($execute_data->func === null) {
            return $function_name === 'main'
                || $function_name === '<main>';
        }

        $func = $dereferencer->deref($execute_data->func);
        $fqn = $func->getFullyQualifiedFunctionName(
            $dereferencer,
            $zend_type_reader,
        );

        return $fqn === $function_name;
    }

    /**
     * Read a class static property.
     * Name format: "App\Cache::$entries"
     */
    private function readStaticProperty(
        ZendExecutorGlobals $eg,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        string $name,
        int $cg_address,
    ): ?VariableValue {
        // Parse "ClassName::$propName"
        $parts = explode('::$', $name, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$class_name, $prop_name] = $parts;
        $class_name_lower = strtolower($class_name);

        // Look up class in EG->class_table
        $class_table_ptr = $eg->class_table;
        if ($class_table_ptr === null) {
            return null;
        }
        $class_table = $dereferencer->deref($class_table_ptr);
        $bucket = $class_table->findByKey(
            $dereferencer,
            $class_name_lower,
        );
        if ($bucket === null) {
            return null;
        }

        // Re-deref for independent CData
        $class_zval = $dereferencer->deref(
            $bucket->val->getPointer(),
        );
        $ce_pointer = $class_zval->value->ce;
        if ($ce_pointer === null) {
            return null;
        }
        $ce = $dereferencer->deref($ce_pointer);

        // Get map_ptr_base from CG if available
        $map_ptr_base = 0;
        if ($cg_address > 0) {
            $cg_pointer = new Pointer(
                \Reli\Lib\PhpInternals\Types\Zend\ZendCompilerGlobals::class,
                $cg_address,
                $zend_type_reader->sizeOf('zend_compiler_globals'),
            );
            $cg = $dereferencer->deref($cg_pointer);
            $map_ptr_base = $cg->map_ptr_base;
        }

        [$prop_root, $prop_path] = self::parsePathExpression($prop_name);

        foreach (
            $ce->getStaticPropertyIterator(
                $dereferencer,
                $zend_type_reader,
                $map_ptr_base,
            ) as $static_name => $zval
        ) {
            if ($static_name === $prop_root) {
                $resolved = $this->resolvePath(
                    $zval,
                    $prop_path,
                    $dereferencer,
                    $zend_type_reader,
                );
                if ($resolved === null) {
                    return null;
                }
                return $this->zvalToVariableValue(
                    $resolved,
                    $dereferencer,
                );
            }
        }
        return null;
    }

    /**
     * Read a function's static variable.
     * Name format: "App\retry()$attempt" or "App\retry()$cache->items"
     */
    private function readFuncStaticVariable(
        ZendExecutorGlobals $eg,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        string $name,
        int $cg_address,
    ): ?VariableValue {
        // Parse "funcName()$varName" using same ()$ pattern as local
        [$func_name, $var_part] = self::parseLocalExpression($name);
        if ($func_name === null) {
            return null; // func_static requires a function name
        }
        [$var_name, $path_segments] = self::parsePathExpression($var_part);
        $func_name_lower = strtolower($func_name);

        // Look up function in EG->function_table
        $func_table_ptr = $eg->function_table;
        if ($func_table_ptr === null) {
            return null;
        }
        $func_table = $dereferencer->deref($func_table_ptr);
        $bucket = $func_table->findByKey(
            $dereferencer,
            $func_name_lower,
        );
        if ($bucket === null) {
            return null;
        }

        // Re-deref for independent CData
        $func_zval = $dereferencer->deref(
            $bucket->val->getPointer(),
        );
        $func_pointer = $func_zval->value->func;
        if ($func_pointer === null) {
            return null;
        }
        $func = $dereferencer->deref($func_pointer);

        if (!$func->isUserFunction()) {
            return null;
        }

        // Try runtime static variables first (PHP 7.4+).
        // op_array->static_variables_ptr__ptr is a ZEND_MAP_PTR
        // that points to the live copy (updated at runtime).
        // op_array->static_variables is the template (initial values).
        $static_vars = $this->resolveRuntimeStaticVars(
            $func,
            $dereferencer,
            $zend_type_reader,
            $cg_address,
        );
        if ($static_vars === null) {
            return null;
        }
        $sv_bucket = $static_vars->findByKey(
            $dereferencer,
            $var_name,
        );
        if ($sv_bucket === null) {
            return null;
        }
        // Re-deref for independent CData (avoid dangling view)
        $sv_zval = $dereferencer->deref($sv_bucket->val->getPointer());

        $resolved = $this->resolvePath(
            $sv_zval,
            $path_segments,
            $dereferencer,
            $zend_type_reader,
        );
        if ($resolved === null) {
            return null;
        }
        return $this->zvalToVariableValue($resolved, $dereferencer);
    }

    private function zvalToVariableValue(
        Zval $zval,
        Dereferencer $dereferencer,
    ): VariableValue {
        // Follow IS_INDIRECT and IS_REFERENCE
        $resolved = $this->resolveIndirectAndRef(
            $zval,
            $dereferencer,
        );
        if ($resolved === null) {
            return new VariableValue(
                VariableValue::TYPE_UNKNOWN,
                null,
                null,
            );
        }
        $zval = $resolved;

        if ($zval->isLong()) {
            return new VariableValue(
                VariableValue::TYPE_LONG,
                $zval->value->lval,
                null,
            );
        }
        if ($zval->isDouble()) {
            return new VariableValue(
                VariableValue::TYPE_DOUBLE,
                $zval->value->dval,
                null,
            );
        }
        if ($zval->isString()) {
            $str_pointer = $zval->value->str;
            if ($str_pointer !== null) {
                $zend_string = $dereferencer->deref($str_pointer);
                return new VariableValue(
                    VariableValue::TYPE_STRING,
                    $zend_string->toString($dereferencer),
                    null,
                );
            }
            return new VariableValue(
                VariableValue::TYPE_STRING,
                '',
                null,
            );
        }
        if ($zval->isArray()) {
            $arr_pointer = $zval->value->arr;
            if ($arr_pointer !== null) {
                $arr = $dereferencer->deref($arr_pointer);
                return new VariableValue(
                    VariableValue::TYPE_ARRAY,
                    null,
                    $arr->nNumOfElements,
                );
            }
            return new VariableValue(
                VariableValue::TYPE_ARRAY,
                null,
                0,
            );
        }
        if ($zval->isBool()) {
            return new VariableValue(
                VariableValue::TYPE_BOOL,
                $zval->getType() === 'IS_TRUE',
                null,
            );
        }
        if ($zval->isNull()) {
            return new VariableValue(
                VariableValue::TYPE_NULL,
                null,
                null,
            );
        }
        return new VariableValue(
            VariableValue::TYPE_UNKNOWN,
            null,
            null,
        );
    }

    /**
     * Parse a name like "cache[users]->items[0]" into
     * root "cache" and path segments ["[users]", "->items", "[0]"].
     *
     * @return array{string, list<array{string, string}>}
     *     [root_name, list of [type, key]] where type is "[]" or "->"
     */
    public static function parsePathExpression(string $name): array
    {
        // Find the first [ or -> after the root name
        $first_bracket = strpos($name, '[');
        $first_arrow = strpos($name, '->');

        $first_path = PHP_INT_MAX;
        if ($first_bracket !== false) {
            $first_path = min($first_path, $first_bracket);
        }
        if ($first_arrow !== false) {
            $first_path = min($first_path, $first_arrow);
        }

        if ($first_path === PHP_INT_MAX) {
            return [$name, []];
        }

        $root = substr($name, 0, $first_path);
        $rest = substr($name, $first_path);
        $segments = [];

        while ($rest !== '') {
            if (str_starts_with($rest, '[')) {
                $close = strpos($rest, ']');
                if ($close === false) {
                    break;
                }
                $key = substr($rest, 1, $close - 1);
                $segments[] = ['[]', $key];
                $rest = substr($rest, $close + 1);
            } elseif (str_starts_with($rest, '->')) {
                $rest = substr($rest, 2);
                // Find next delimiter
                $next_bracket = strpos($rest, '[');
                $next_arrow = strpos($rest, '->');
                $end = strlen($rest);
                if ($next_bracket !== false) {
                    $end = min($end, $next_bracket);
                }
                if ($next_arrow !== false) {
                    $end = min($end, $next_arrow);
                }
                $prop = substr($rest, 0, $end);
                $segments[] = ['->', $prop];
                $rest = substr($rest, $end);
            } else {
                break;
            }
        }

        return [$root, $segments];
    }

    /**
     * Resolve a path of array keys and object properties from a zval.
     *
     * @param list<array{string, string}> $path_segments
     */
    /**
     * Resolve runtime static variables for a function.
     *
     * PHP 7.4+ stores static vars via ZEND_MAP_PTR. The op_array has:
     * - static_variables: template (initial values)
     * - static_variables_ptr__ptr: MAP_PTR to runtime copy
     *
     * Returns the runtime ZendArray if available, falls back to template.
     */
    private function resolveRuntimeStaticVars(
        ZendFunction $func,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        int $cg_address,
    ): ?ZendArray {
        $op_array = $func->op_array;

        // Try MAP_PTR resolution (PHP 7.4+)
        $map_ptr_raw = $op_array->static_variables_ptr;
        if ($map_ptr_raw !== 0 && $cg_address > 0) {
            $map_ptr_base = $this->getMapPtrBase(
                $cg_address,
                $dereferencer,
                $zend_type_reader,
            );
            $resolved_address = $zend_type_reader->resolveMapPtr(
                $map_ptr_base,
                $map_ptr_raw,
                $dereferencer,
            );
            if ($resolved_address !== 0) {
                $runtime_ptr = new Pointer(
                    ZendArray::class,
                    $resolved_address,
                    $zend_type_reader->sizeOf('HashTable'),
                );
                return $dereferencer->deref($runtime_ptr);
            }
        }

        // Fall back to template
        if ($op_array->static_variables !== null) {
            return $dereferencer->deref($op_array->static_variables);
        }

        return null;
    }

    private ?int $map_ptr_base_cache = null;

    private function getMapPtrBase(
        int $cg_address,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): int {
        if ($this->map_ptr_base_cache !== null) {
            return $this->map_ptr_base_cache;
        }
        $cg_pointer = new Pointer(
            ZendCompilerGlobals::class,
            $cg_address,
            $zend_type_reader->sizeOf('zend_compiler_globals'),
        );
        $cg = $dereferencer->deref($cg_pointer);
        $this->map_ptr_base_cache = $cg->map_ptr_base;
        return $this->map_ptr_base_cache;
    }

    /**
     * Follow IS_INDIRECT and IS_REFERENCE pointers to the real zval.
     */
    private function resolveIndirectAndRef(
        Zval $zval,
        Dereferencer $dereferencer,
        ?ZendTypeReader $zend_type_reader = null,
    ): ?Zval {
        $current = $zval;
        $limit = 10;
        $zval_size = $zend_type_reader !== null
            ? $zend_type_reader->sizeOf('zval')
            : 16;

        while ($limit-- > 0) {
            if ($current->isIndirect()) {
                $ptr = $current->value->getAsPointer(
                    Zval::class,
                    $zval_size,
                );
                $current = $dereferencer->deref($ptr);
                continue;
            }
            if ($current->isReference()) {
                $ref_pointer = $current->value->ref;
                if ($ref_pointer === null) {
                    return null;
                }
                $ref = $dereferencer->deref($ref_pointer);
                // Re-deref val for independent CData
                // (ref->val is a view into ref's CData)
                $current = $dereferencer->deref(
                    $ref->val->getPointer(),
                );
                continue;
            }
            break;
        }

        return $current->isUndef() ? null : $current;
    }

    private function resolvePath(
        Zval $zval,
        array $path_segments,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): ?Zval {
        $current = $zval;

        // Follow indirects and references
        $current = $this->resolveIndirectAndRef(
            $current,
            $dereferencer,
            $zend_type_reader,
        );
        if ($current === null) {
            return null;
        }

        /** @var array{string, string} $segment */
        foreach ($path_segments as $segment) {
            [$type, $key] = $segment;
            $current = $this->resolveIndirectAndRef(
                $current,
                $dereferencer,
                $zend_type_reader,
            );
            if ($current === null) {
                return null;
            }

            if ($type === '[]') {
                // Array key access
                if (!$current->isArray()) {
                    return null;
                }
                $arr_pointer = $current->value->arr;
                if ($arr_pointer === null) {
                    return null;
                }
                $arr = $dereferencer->deref($arr_pointer);
                $bucket = $arr->findByKey($dereferencer, $key);
                if ($bucket === null) {
                    return null;
                }
                // Re-deref the zval from its pointer to get an
                // independent CData copy. Bucket->val is a CData
                // view into the Bucket's memory that becomes
                // dangling when the Bucket is GC'd.
                $current = $dereferencer->deref(
                    $bucket->val->getPointer(),
                );
            } elseif ($type === '->') {
                // Object property access
                if (!$current->isObject()) {
                    return null;
                }
                $obj_pointer = $current->value->obj;
                if ($obj_pointer === null) {
                    return null;
                }
                $obj = $dereferencer->deref($obj_pointer);
                $found = false;
                /** @var iterable<string, Zval> $props */
                $props = $obj->getPropertiesIterator(
                    $dereferencer,
                    $zend_type_reader,
                );
                foreach ($props as $prop_name => $prop_zval) {
                    if ($prop_name === $key) {
                        // Re-deref for independent CData copy
                        $current = $dereferencer->deref(
                            $prop_zval->getPointer(),
                        );
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return null;
                }
            }
        }

        return $current;
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    private function getDereferencer(
        ProcessSpecifier $process_specifier,
        string $php_version,
    ): Dereferencer {
        $zend_type_reader = $this->zend_type_reader_creator->create(
            $php_version,
        );
        return new RemoteProcessDereferencer(
            $this->memory_reader,
            $process_specifier,
            new ZendCastedTypeProvider($zend_type_reader),
            new VersionedPointedTypeResolver($php_version),
        );
    }
}
