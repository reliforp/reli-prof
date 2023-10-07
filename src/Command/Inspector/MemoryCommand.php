<?php

namespace Reli\Command\Inspector;

use FFI\CData;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Lib\FFI\CastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendClassConstant;
use Reli\Lib\PhpInternals\Types\Zend\ZendClassEntry;
use Reli\Lib\PhpInternals\Types\Zend\ZendCompilerGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MemoryCommand extends Command
{
    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private TargetProcessResolver $target_process_resolver,
        private PhpZendMemoryManagerChunkFinder $chunk_finder,
        private MemoryReaderInterface $memory_reader,
        private PhpVersionDetector $php_version_detector,
        private ZendTypeReaderCreator $zend_type_reader_creator,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('inspector:memory')
            ->setDescription('get memory usage from an outer process or thread')
        ;
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $target_php_settings = $this->target_php_settings_from_console_input->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input->createSettings($input);

        $process_specifier = $this->target_process_resolver->resolve($target_process_settings);

        $target_php_settings = $this->php_version_detector->decidePhpVersion(
            $process_specifier,
            $target_php_settings
        );

        $result = $this->memory_reader->read(
            $process_specifier->pid,
            $main_chunk_address = $this->getChunkAddress(
                $process_specifier->pid,
                $target_php_settings->php_version,
                $this->memory_reader
            ),
            0x200000
        );
        $zend_type_reader = $this->zend_type_reader_creator->create($target_php_settings->php_version);
        $zend_mm_main_chunk = $zend_type_reader->readAs('zend_mm_chunk', $result);
        $size = $zend_mm_main_chunk->casted->heap_slot->size;
        $real_size = $zend_mm_main_chunk->casted->heap_slot->real_size;

        $eg_address = $this->php_globals_finder->findExecutorGlobals($process_specifier, $target_php_settings);
        $eg_pointer = new Pointer(
            ZendExecutorGlobals::class,
            $eg_address,
            $zend_type_reader->sizeOf('zend_executor_globals')
        );
        $cg_address = $this->php_globals_finder->findCompilerGlobals($process_specifier, $target_php_settings);
        $cg_pointer = new Pointer(
            ZendCompilerGlobals::class,
            $cg_address,
            $zend_type_reader->sizeOf('zend_compiler_globals')
        );
        $remote_process_dereferencer = new RemoteProcessDereferencer(
            $this->memory_reader,
            $process_specifier,
            $casted_type_provider = new ZendCastedTypeProvider(
                $zend_type_reader
            ),
        );
        /** @var ZendExecutorGlobals $eg */
        $eg = $remote_process_dereferencer->deref($eg_pointer);
        $function_table = $remote_process_dereferencer->deref($eg->function_table);
        $class_table = $remote_process_dereferencer->deref($eg->class_table);
        $zend_constants = $remote_process_dereferencer->deref($eg->zend_constants);

        $vm_stack_curent = $remote_process_dereferencer->deref($eg->vm_stack);
        $vm_stack_size = 0;
        foreach ($vm_stack_curent->iterateStackChain($remote_process_dereferencer) as $vm_stack) {
            $vm_stack_size += $vm_stack->getSize();
        }
        /** @var ZendCompilerGlobals $cg */
        $cg = $remote_process_dereferencer->deref($cg_pointer);

        $dump_symbol_table = function (ZendArray $array) use (&$dump_symbol_table, $remote_process_dereferencer, &$dump_zval) {
            $result = [];
            $pos = 0;
            foreach ($array->getItemIterator($remote_process_dereferencer) as $key => $zval) {
                $key_name = $key ?? $pos++;
                $result[$key_name] = $dump_zval($zval);
            }
            return $result;
        };
        $dump_function_table = function (ZendArray $array) use ($dump_symbol_table, $remote_process_dereferencer) {
            $result = [];
            $pos = 0;
            foreach ($array->getItemIterator($remote_process_dereferencer) as $key => $zval) {
                $function_name = $key ?? $pos++;
                $func = $remote_process_dereferencer->deref($zval->value->func);
                if ($func->type === 2) {
                    $static_variables = [];
                    if (!is_null($func->op_array->static_variables)) {
                        $static_variables = $dump_symbol_table(
                            $remote_process_dereferencer->deref($func->op_array->static_variables)
                        );
                    }
                    $result[$function_name] = [
                        'op_array_size' => $func->op_array->last * 48,
                        'static_variables' => $static_variables,
                        'overhead' => 128,
                    ];
                }
            }
            return $result;
        };
        $dump_class_constants_table = function (ZendClassEntry $ce) use ($remote_process_dereferencer, $dump_symbol_table, $zend_type_reader, &$dump_zval) {
            $result = [];
            $pos = 0;
            foreach ($ce->constants_table->getItemIterator($remote_process_dereferencer) as $key => $zval_ptr) {
                $constant_name = $key ?? $pos++;
                $class_constant_ptr = $zval_ptr->value->getAsPointer(
                    ZendClassConstant::class,
                    $zend_type_reader->sizeOf(ZendClassConstant::getCTypeName()),
                );
                $class_constant = $remote_process_dereferencer->deref(
                    $class_constant_ptr
                );

                $zval = $class_constant->value;
                $result[$constant_name] = $dump_zval($zval);
            }
            return $result;
        };
        $dump_class_table = function (ZendArray $array) use ($dump_symbol_table, $dump_function_table, $dump_class_constants_table, $remote_process_dereferencer, $zend_type_reader, &$dump_zval) {
            $result = [];
            $pos = 0;
            foreach ($array->getItemIterator($remote_process_dereferencer) as $key => $zval) {
                $class_name = $key ?? $pos++;
                /** @var ZendClassEntry $class_entry */
                $class_entry = $remote_process_dereferencer->deref($zval->value->ce);
                if ($class_entry->isInternal()) {
                    continue;
                }
                $analyzed_static_member_table = [];
                $static_property_iterator = $class_entry->getStaticPropertyIterator(
                    $remote_process_dereferencer,
                    $zend_type_reader
                );
                foreach ($static_property_iterator as $name => $value) {
                    if (!is_null($value)) {
                        $analyzed_static_member_table[$name] = $dump_zval($value);
                    } else {
                        $analyzed_static_member_table[$name] = 16;
                    }
                }
                $result[$class_name] = [
                    'static_variables' => $analyzed_static_member_table,
                    'methods' => $dump_function_table($class_entry->function_table),
                    'constants' => $dump_class_constants_table($class_entry),
                    'overhead' => 128,
                ];
            }
            return $result;
        };
        $recursion_memo = [];
        $dump_zval = function (Zval $zval) use (&$dump_zval, &$recursion_memo, $remote_process_dereferencer, &$dump_symbol_table, $zend_type_reader): int|array {
            if ($zval->isArray()) {
                $pos = 0;
                $result = [];
                if (isset($recursion_memo[$zval->value->arr->address])) {
                    return [$zval->value->arr->address => 16];
                }
                $recursion_memo[$zval->value->arr->address] = true;
                $array = $remote_process_dereferencer->deref($zval->value->arr);
                foreach ($array->getItemIterator($remote_process_dereferencer) as $key => $zval) {
                    $key_name = $key ?? $pos++;
                    $result[$key_name] = $dump_zval($zval);
                }

                return [
                    'address' => $zval->value->arr->address,
                    'overhead' => 64,
                    'items' => $result,
                ];
            } elseif ($zval->isObject()) {
                if (isset($recursion_memo[$zval->value->obj->address])) {
                    return [$zval->value->obj->address => 16];
                }
                $recursion_memo[$zval->value->obj->address] = true;
                $obj = $remote_process_dereferencer->deref($zval->value->obj);
                $dynamic_properties = [];
                if (!is_null($obj->properties) and !is_null($obj->ce) and !$obj->isEnum($remote_process_dereferencer)) {
                    $dynamic_properties = $dump_symbol_table(
                        $remote_process_dereferencer->deref($obj->properties),
                    );
                }
                $properties = [];
                $properties_iterator = $obj->getPropertiesIterator(
                    $remote_process_dereferencer,
                    $zend_type_reader,
                    $zval->value->obj,
                );
                foreach ($properties_iterator as $name => $property) {
                    $properties[$name] = $dump_zval($property);
                }

                return [
                    'address' => $zval->value->obj->address,
                    'overhead' => 128,
                    'dynamic_properties' => $dynamic_properties,
                    'properties' => $properties,
                ];
            } elseif ($zval->isString()) {
                $str = $remote_process_dereferencer->deref($zval->value->str);
                return [
                    'address' => $zval->value->str->address,
                    'overhead' => 128,
                    'len' => $str->len,
                ];
            } else {
                return 16;
            }
        };

        $call_frames = [];
        $execute_data = $remote_process_dereferencer->deref($eg->current_execute_data);
        foreach ($execute_data->iterateStackChain($remote_process_dereferencer) as $execute_data) {
            $current_function_name = $execute_data->getFunctionName($remote_process_dereferencer);
            $current_call_frame = [];
            $local_variables_iterator = $execute_data->getVariables($remote_process_dereferencer, $zend_type_reader);
            foreach ($local_variables_iterator as $name => $value) {
                $current_call_frame[$name] = $dump_zval($value);
            }
            $call_frames[] = [
                'function_name' => $current_function_name,
                'local_variables' => $current_call_frame,
            ];
        }
        $pick_heap_allocated = function (array $call_frames) {
            $result = [];
            foreach ($call_frames as $key => $frame) {
                $local_variables = [];
                foreach ($frame['local_variables'] as $name => $variable) {
                    if (isset($variable['address'])) {
                        $local_variables[$name] = $variable;
                    }
                    if (is_int($variable)) {
                        $local_variables[$name] = 'stack allocated';
                    }
                }
                $result[$key] = [
                    'function_name' => $frame['function_name'],
                    'local_variables' => $local_variables,
                ];
            }
            return $result;
        };

        $sum = function (array $result) use (&$sum) {
            $total = 0;
            foreach ($result as $key => $item) {
                if (is_array($item)) {
                    $total += $sum($item);
                } elseif ($key !== 'address' and is_int($item)) {
                    $total += $item;
                }
            }
            return $total;
        };
        $symbol_table_result = $dump_symbol_table($eg->symbol_table);
        $function_table_result = $dump_function_table($function_table);
        $class_table_result = $dump_class_table($class_table);
        $compiler_arena = $cg->getSizeOfArena($remote_process_dereferencer);
        $compiler_ast_arena = $cg->getSizeOfAstArena($remote_process_dereferencer);
        $data_traced = [
            'call_frames' => $call_frames,
            'global_variables' => $symbol_table_result,
            'functions' => $function_table_result,
            'classes' => $class_table_result,
        ];
//        var_dump($data_traced);
//        var_dump($call_frames);
//        die;
        $analyzed_result = [
            'call_frames' => $sum($pick_heap_allocated($call_frames)),
            'global_variables' => $sum($symbol_table_result),
            'functions' => $sum($function_table_result),
            'classes' => $sum($class_table_result),
            'vm_stack' => $vm_stack_size,
            'compiler_arena' => $compiler_arena,
            'compiler_ast_arena' => $compiler_ast_arena,
        ];
        file_put_contents('traced_data.json', json_encode($data_traced, JSON_PRETTY_PRINT));
        var_dump($analyzed_result);

        var_dump([
            'heap_size' => $size,
            'heap_real_size' => $real_size,
            'analyzed_sum' => $sum($analyzed_result),
            'analyzed_percentage' => $sum($analyzed_result) / $size * 100,
        ]);
        return 0;
    }

    private function getChunkAddress(int $pid, string $php_version, MemoryReaderInterface $memory_reader): int
    {
        $chunk_address = $this->chunk_finder->findAddress(
            $pid,
            $php_version,
            $memory_reader
        );
        if (is_null($chunk_address)) {
            throw new \RuntimeException('chunk address not found');
        }
        return $chunk_address;
    }
}
