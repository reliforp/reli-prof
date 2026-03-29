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
        [$root, $path_segments] = self::parsePathExpression($name);
        $bucket = $eg->symbol_table->findByKey($dereferencer, $root);
        if ($bucket === null) {
            return null;
        }
        $zval = $this->resolvePath(
            $bucket->val,
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
     * Starts from current_execute_data and traverses prev_execute_data
     * until the variable is found. This handles the case where the
     * process is stopped inside an internal function (e.g. fgets)
     * and the user variable is in a parent frame.
     */
    private function readLocalVariable(
        ZendExecutorGlobals $eg,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        string $name,
    ): ?VariableValue {
        [$root, $path_segments] = self::parsePathExpression($name);
        $frame_pointer = $eg->current_execute_data;

        // Walk up the call stack
        while ($frame_pointer !== null) {
            $execute_data = $dereferencer->deref($frame_pointer);

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

            $frame_pointer = $execute_data->prev_execute_data;
        }

        return null;
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

        // bucket->val is a zval pointing to ZendClassEntry
        $ce_pointer = $bucket->val->value->ce;
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
     * Name format: "App\retry::$attempt"
     */
    private function readFuncStaticVariable(
        ZendExecutorGlobals $eg,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        string $name,
    ): ?VariableValue {
        // Parse "funcName::$varName" or "funcName::$varName->path[key]"
        $parts = explode('::$', $name, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$func_name, $var_name_with_path] = $parts;
        [$var_name, $path_segments] = self::parsePathExpression(
            $var_name_with_path,
        );
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

        // bucket->val is a zval pointing to ZendFunction
        $func_pointer = $bucket->val->value->func;
        if ($func_pointer === null) {
            return null;
        }
        $func = $dereferencer->deref($func_pointer);

        if (!$func->isUserFunction()) {
            return null;
        }

        $static_vars_ptr = $func->op_array->static_variables;
        if ($static_vars_ptr === null) {
            return null;
        }

        $static_vars = $dereferencer->deref($static_vars_ptr);
        $sv_bucket = $static_vars->findByKey(
            $dereferencer,
            $var_name,
        );
        if ($sv_bucket === null) {
            return null;
        }

        $resolved = $this->resolvePath(
            $sv_bucket->val,
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
        if ($zval->isReference()) {
            $ref_pointer = $zval->value->ref;
            if ($ref_pointer !== null) {
                $ref = $dereferencer->deref($ref_pointer);
                return $this->zvalToVariableValue(
                    $ref->val,
                    $dereferencer,
                );
            }
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
    private function resolvePath(
        Zval $zval,
        array $path_segments,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): ?Zval {
        $current = $zval;

        // Follow references first
        while ($current->isReference()) {
            $ref_pointer = $current->value->ref;
            if ($ref_pointer === null) {
                return null;
            }
            $ref = $dereferencer->deref($ref_pointer);
            $current = $ref->val;
        }

        foreach ($path_segments as [$type, $key]) {
            // Follow references at each step
            while ($current->isReference()) {
                $ref_pointer = $current->value->ref;
                if ($ref_pointer === null) {
                    return null;
                }
                $ref = $dereferencer->deref($ref_pointer);
                $current = $ref->val;
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
                $current = $bucket->val;
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
                        $current = $prop_zval;
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
