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
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Reads PHP variable values from a target process.
 *
 * Currently supports:
 * - global scope: reads from EG->symbol_table via ZendArray::findByKey()
 *
 * Future: local, static, func_static scopes
 */
final class VariableReader
{
    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
    ) {
    }

    /**
     * Read variable values for the given watch-var triggers.
     *
     * @param list<VariableValueTrigger> $triggers
     * @param TargetPhpSettings<'v70'|'v71'|'v72'|'v73'|'v74'|'v80'|'v81'|'v82'|'v83'|'v84'|'v85'> $target_php_settings
     * @return array<string, VariableValue> Keyed by "scope::name"
     */
    public function readVariables(
        array $triggers,
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
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

        // Read EG
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
                        $name,
                    ),
                    default => null,
                    // 'local', 'static', 'func_static'
                    // are left for future implementation
                };
                if ($value !== null) {
                    $results[$key] = $value;
                }
            } catch (\Throwable) {
                // Variable may not exist or be inaccessible
                continue;
            }
        }

        return $results;
    }

    private function readGlobalVariable(
        ZendExecutorGlobals $eg,
        Dereferencer $dereferencer,
        string $name,
    ): ?VariableValue {
        $symbol_table = $eg->symbol_table;
        $bucket = $symbol_table->findByKey($dereferencer, $name);
        if ($bucket === null) {
            return null;
        }

        return $this->zvalToVariableValue(
            $bucket->val,
            $dereferencer,
        );
    }

    private function zvalToVariableValue(
        \Reli\Lib\PhpInternals\Types\Zend\Zval $zval,
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
                $str_val = $zend_string->toString($dereferencer);
                return new VariableValue(
                    VariableValue::TYPE_STRING,
                    $str_val,
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
            // Follow the reference
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
     * @param value-of<\Reli\Lib\PhpInternals\ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
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
