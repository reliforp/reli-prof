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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader;

use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryLimitErrorDetails;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendClassConstant;
use Reli\Lib\PhpInternals\Types\Zend\ZendClassEntry;
use Reli\Lib\PhpInternals\Types\Zend\ZendClosure;
use Reli\Lib\PhpInternals\Types\Zend\ZendCompilerGlobals;
use Reli\Lib\PhpInternals\Types\C\PointerArray;
use Reli\Lib\PhpInternals\Types\C\RawInt64;
use Reli\Lib\PhpInternals\Types\Zend\ZendFiber;
use Reli\Lib\PhpInternals\Types\Zend\ZendFiberStack;
use Reli\Lib\PhpInternals\Types\Zend\ZendGenerator;
use Reli\Lib\PhpInternals\Types\Zend\ZendVmStack;
use Reli\Lib\PhpInternals\Types\Zend\ZendConstant;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecuteData;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendFunction;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmChunk;
use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpInternals\Types\Zend\ZendObjectsStore;
use Reli\Lib\PhpInternals\Types\Zend\ZendReference;
use Reli\Lib\PhpInternals\Types\C\RawString;
use Reli\Lib\PhpInternals\Types\Php\PdoColumnData;
use Reli\Lib\PhpInternals\Types\Php\PdoMysqlDbHandle;
use Reli\Lib\PhpInternals\Types\Php\PdoMysqlStmt;
use Reli\Lib\PhpInternals\Types\Php\PdoPgsqlDbHandle;
use Reli\Lib\PhpInternals\Types\Php\PdoPgsqlStmt;
use Reli\Lib\PhpInternals\Types\Php\PdoSqliteDbHandle;
use Reli\Lib\PhpInternals\Types\Php\PdoSqliteStmt;
use Reli\Lib\PhpInternals\Types\Php\MysqlndRes;
use Reli\Lib\PhpInternals\Types\Php\MysqlndResBuffered;
use Reli\Lib\PhpInternals\Types\Php\PhpStream;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamMemoryData;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamOps;
use Reli\Lib\PhpInternals\Types\Php\PhpStreamTempData;
use Reli\Lib\PhpInternals\Types\Zend\ZendResource;
use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpInternals\Types\Zend\ZendWeakMap;
use Reli\Lib\PhpInternals\Types\Zend\ZendWeakReference;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\CallFrameHeaderMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\DefaultPropertiesTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\DefaultStaticMembersTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\DynamicFuncDefsTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\LocalVariableNameTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ObjectsStoreMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\RuntimeCacheMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\StaticMembersTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\VmStackMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArenaMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArgInfosMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableOverheadMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendClassConstantMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendClassEntryMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendConstantMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmChunkMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmHugeListMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectHandlersMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendOpArrayBodyMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendOpArrayHeaderMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendPropertyInfoMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendReferenceMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MysqlndMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PdoDbhMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\PdoDriverDataMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendResourceMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArgInfoContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArgInfosContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayElementContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayElementsContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayHeaderContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayPossibleOverheadContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\CallFrameContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\CallFramesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\CallFrameVariableTableContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassConstantContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassConstantInfoContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassConstantsContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassDefinitionContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassEntryContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClassStaticPropertiesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ClosureContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\DefaultPropertiesTableContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\DefaultStaticPropertiesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\DefinedClassesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\DefinedFunctionsContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\DynamicFuncDefsContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\FunctionDefinitionContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\FiberContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\GeneratorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\GlobalConstantContext;
use Reli\Lib\PhpInternals\Types\Php\PhpBasicGlobals;
use Reli\Lib\PhpInternals\Types\Php\PhpShutdownFunctionEntry;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\GlobalCallbacksContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\GlobalConstantsContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\GlobalVariablesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\IncludedFilesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\InternalFunctionDefinitionContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ModulesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\StandardModuleContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\LocalVariableNameTableContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectPropertiesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectsStoreContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\OpArrayContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\PhpReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\PropertiesInfoContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\PropertyInfoContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ResourceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\RuntimeCacheContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ScalarValueContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\SentinelContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\StringContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\TopReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\UserFunctionDefinitionContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\WeakMapContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\WeakReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\CachingDereferencer;
use Reli\Lib\Process\Pointer\RemoteProcessDereferencer;
use Reli\Lib\Process\ProcessSpecifier;

/** @psalm-import-type VersionDecided from TargetPhpSettings */
final class MemoryLocationsCollector
{
    private ?ZendTypeReader $zend_type_reader = null;
    private ?UserFunctionDefinitionContext $memory_limit_error_function_context = null;
    private ?MemoryLocations $fiber_vm_stack_memory_locations = null;
    private ?MemoryLocations $chunk_memory_locations = null;

    /** Set during streaming collection for sub-tree level emit+release */
    private ?ContextTreeSink $streaming_sink = null;
    private ?ContextAnalyzer $streaming_analyzer = null;
    /** @var \WeakMap<ReferenceContext, int>|null */
    private ?\WeakMap $streaming_memo = null;
    private ?ContextPools $streaming_context_pools = null;

    /**
     * When true, collectZendObjectPointer skips deep recursion for unseen
     * objects, deferring them to the objects_store bucket loop. This prevents
     * deep object graph traversal from within a single bucket.
     */
    private bool $defer_unseen_objects = false;

    /**
     * Edges deferred during objects_store collection. Each entry records
     * a property reference to an object that was not yet collected.
     * After all objects are collected, these are resolved via the sentinel
     * map and emitted as reference edges.
     *
     * @var list<array{int, int, string, string}> [parent_node_id, child_address, link_name, pointer_type]
     */
    private array $deferred_object_edges = [];

    /**
     * The node_id of the innermost streaming parent that is currently
     * being populated. Used by defer logic to record edges.
     */
    private ?int $current_streaming_parent_node_id = null;

    /**
     * The link name under which the current child is being collected.
     * Used by defer logic to record the correct edge name.
     */
    private ?string $current_streaming_link_name = null;

    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
        private PhpZendMemoryManagerChunkFinder $chunk_finder,
    ) {
    }

    /**
     * In streaming mode, convert current pool entries to sentinels
     * and clear the pools. This releases the heavy Context objects
     * that were created during the current iteration, keeping only
     * the address→node_id map for cross-reference deduplication.
     */
    private function flushPoolsIfStreaming(): void
    {
        if (
            $this->streaming_memo === null
            || $this->streaming_context_pools === null
        ) {
            return;
        }
        $this->streaming_context_pools->convertToSentinels($this->streaming_memo);
        $this->streaming_context_pools->clear();
    }

    /**
     * In streaming mode, register a parent node in the analyzer's memo
     * and emit it to the sink, so that children can be emitted as
     * sub-nodes during collection. Returns the assigned node_id, or
     * null if not in streaming mode.
     */
    private function registerParentIfStreaming(
        ReferenceContext $parent,
    ): ?int {
        if (
            $this->streaming_sink === null
            || $this->streaming_analyzer === null
            || $this->streaming_memo === null
        ) {
            return null;
        }
        $existing = $this->streaming_memo[$parent] ?? null;
        if ($existing !== null) {
            return $existing;
        }
        $node_id = $this->streaming_analyzer->assignNodeId($parent, $this->streaming_memo);
        return $node_id;
    }

    /**
     * In streaming mode, emit a child context that was just collected,
     * and return a SentinelContext so the parent holds only node_id.
     * In non-streaming mode, return the child as-is for normal tree building.
     */
    private function emitChildIfStreaming(
        string $link_name,
        ReferenceContext $child,
        int $parent_node_id,
    ): ReferenceContext {
        if (
            $this->streaming_sink === null
            || $this->streaming_analyzer === null
            || $this->streaming_memo === null
        ) {
            return $child;
        }
        // Already a sentinel — no need to emit again
        if ($child instanceof SentinelContext) {
            return $child;
        }
        // Emit the child subtree to DB
        $this->streaming_analyzer->analyzeSingleLink(
            $link_name,
            $child,
            $this->streaming_sink,
            $parent_node_id,
            $this->streaming_memo,
        );
        // Look up the node_id that was assigned
        $node_id = $this->streaming_memo[$child] ?? null;
        if ($node_id !== null) {
            return new SentinelContext($node_id);
        }
        return $child;
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    public function getTypeReader(string $php_version): ZendTypeReader
    {
        if (is_null($this->zend_type_reader)) {
            $this->zend_type_reader = $this->zend_type_reader_creator->create($php_version);
        }
        return $this->zend_type_reader;
    }

    /**
     * @param value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS> $php_version
     */
    private function getDereferencer(int $pid, string $php_version, bool $enable_cache = false): Dereferencer
    {
        $inner = new RemoteProcessDereferencer(
            $this->memory_reader,
            new ProcessSpecifier($pid),
            new ZendCastedTypeProvider(
                $this->getTypeReader($php_version),
            ),
            new VersionedPointedTypeResolver($php_version)
        );
        if ($enable_cache) {
            return new CachingDereferencer($inner);
        }
        return $inner;
    }

    /** @param TargetPhpSettings<VersionDecided> $target_php_settings */
    private function getMainChunkAddress(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        Dereferencer $dereferencer,
    ): int {
        $chunk_address = $this->chunk_finder->findAddress(
            $process_specifier,
            $target_php_settings,
            $eg_address,
            $dereferencer,
        );
        if (is_null($chunk_address)) {
            throw new \RuntimeException('chunk address not found');
        }
        return $chunk_address;
    }

    /** @param TargetPhpSettings<VersionDecided> $target_php_settings */
    public function collectAll(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        int $cg_address,
        ?MemoryLimitErrorDetails $memory_limit_error_details = null,
        ?int $bg_address = null,
        ?ContextTreeSink $sink = null,
    ): CollectedMemories {
        $pid = $process_specifier->pid;
        $php_version = $target_php_settings->php_version;
        $dereferencer = $this->getDereferencer($pid, $php_version, enable_cache: $sink !== null);
        $zend_type_reader = $this->zend_type_reader_creator->create($php_version);

        $main_chunk_header_pointer = new Pointer(
            ZendMmChunk::class,
            $this->getMainChunkAddress(
                $process_specifier,
                $target_php_settings,
                $eg_address,
                $dereferencer,
            ),
            $zend_type_reader->sizeOf('zend_mm_chunk'),
        );

        $memory_locations = $sink !== null
            ? MemoryLocations::createLightweight()
            : new MemoryLocations();
        $chunk_memory_locations = new MemoryLocations();
        $this->chunk_memory_locations = $chunk_memory_locations;

        $zend_mm_main_chunk = $dereferencer->deref($main_chunk_header_pointer);
        foreach ($zend_mm_main_chunk->iterateChunks($dereferencer) as $chunk) {
            $chunk_memory_location = ZendMmChunkMemoryLocation::fromZendMmChunk($chunk);
            $chunk_memory_locations->add(
                $chunk_memory_location
            );
        }
        $huge_memory_locations = new MemoryLocations();
        foreach ($zend_mm_main_chunk->heap_slot->iterateHugeList($dereferencer) as $huge_list) {
            $huge_memory_locations->add(
                ZendMmChunkMemoryLocation::fromZendMmHugeList($huge_list)
            );
            $memory_locations->add(
                ZendMmHugeListMemoryLocation::fromZendMmHugeList($huge_list)
            );
        }

        $memory_get_usage_size = $zend_mm_main_chunk->heap_slot->size;
        $memory_get_usage_real_size = $zend_mm_main_chunk->heap_slot->real_size;
        $memory_get_peak_usage = $zend_mm_main_chunk->heap_slot->peak;
        $memory_limit = $zend_mm_main_chunk->heap_slot->limit;
        $cached_chunks_size = $zend_mm_main_chunk->heap_slot->cached_chunks_count * ZendMmChunk::SIZE;

        $eg_pointer = new Pointer(
            ZendExecutorGlobals::class,
            $eg_address,
            $zend_type_reader->sizeOf('zend_executor_globals')
        );
        $cg_pointer = new Pointer(
            ZendCompilerGlobals::class,
            $cg_address,
            $zend_type_reader->sizeOf('zend_compiler_globals')
        );

        $compiler_arena_memory_locations = new MemoryLocations();
        /** @var ZendCompilerGlobals $cg */
        $cg = $dereferencer->deref($cg_pointer);
        if ($cg->arena !== null) {
            $arena_root = $dereferencer->deref($cg->arena);
            foreach ($arena_root->iterateChain($dereferencer) as $arena) {
                $compiler_arena_memory_locations->add(
                    ZendArenaMemoryLocation::fromZendArena($arena)
                );
            }
        }

        if ($cg->ast_arena !== null) {
            $ast_arena_root = $dereferencer->deref($cg->ast_arena);
            foreach ($ast_arena_root->iterateChain($dereferencer) as $ast_arena) {
                $compiler_arena_memory_locations->add(
                    ZendArenaMemoryLocation::fromZendArena($ast_arena)
                );
            }
        }

        /** @var ZendExecutorGlobals $eg */
        $eg = $dereferencer->deref($eg_pointer);

        $vm_stack_memory_locations = new MemoryLocations();
        if (!is_null($eg->vm_stack)) {
            $vm_stack_curent = $dereferencer->deref($eg->vm_stack);
            foreach ($vm_stack_curent->iterateStackChain($dereferencer) as $vm_stack) {
                $vm_stack_memory_locations->add(
                    VmStackMemoryLocation::fromZendVmStack($vm_stack),
                );
            }
        }

        $this->fiber_vm_stack_memory_locations = $vm_stack_memory_locations;

        $context_pools = ContextPools::createDefault();

        // In streaming mode, set up the analyzer and memo early so we can
        // emit each branch right after collection and release it.
        // Also set instance properties so collect* methods can emit sub-trees.
        $analyzer = $sink !== null ? new ContextAnalyzer() : null;
        /** @var \WeakMap<ReferenceContext, int>|null $memo */
        $memo = $sink !== null ? new \WeakMap() : null;

        if ($sink !== null && $analyzer !== null && $memo !== null) {
            $this->streaming_sink = $sink;
            $this->streaming_analyzer = $analyzer;
            $this->streaming_memo = $memo;
            $this->streaming_context_pools = $context_pools;
        }

        $included_files_context = $this->collectIncludedFiles(
            $eg->included_files,
            $dereferencer,
            $memory_locations,
            $context_pools,
        );
        if ($sink !== null && $analyzer !== null && $memo !== null) {
            $analyzer->analyzeSingleLink('included_files', $included_files_context, $sink, null, $memo);
            unset($included_files_context);
            $context_pools->convertToSentinels($memo);
        }

        $interned_strings_context = $this->collectInternedStrings(
            $cg->interned_strings,
            $cg->map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
        );
        if ($sink !== null && $analyzer !== null && $memo !== null) {
            $analyzer->analyzeSingleLink('interned_strings', $interned_strings_context, $sink, null, $memo);
            unset($interned_strings_context);
            $context_pools->convertToSentinels($memo);
        }

        assert(!is_null($eg->function_table));
        assert(!is_null($eg->class_table));
        assert(!is_null($eg->zend_constants));

        $function_table = $dereferencer->deref($eg->function_table);
        $class_table = $dereferencer->deref($eg->class_table);
        $zend_constants = $dereferencer->deref($eg->zend_constants);

        if ($sink !== null && $analyzer !== null && $memo !== null) {
            // Streaming mode: collect objects_store, function_table, and
            // class_table BEFORE global_variables and call_frames. This
            // populates the sentinel map so later phases hit sentinels
            // instead of recursively expanding the object graph.
            $objects_store_context = $this->collectObjectsStore(
                $eg->objects_store,
                $cg->map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            $analyzer->analyzeSingleLink('objects_store', $objects_store_context, $sink, null, $memo);
            unset($objects_store_context);
            $context_pools->convertToSentinels($memo);

            $defined_functions_context = $this->collectFunctionTable(
                $function_table,
                $cg->map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            $analyzer->analyzeSingleLink('function_table', $defined_functions_context, $sink, null, $memo);
            unset($defined_functions_context);
            $context_pools->convertToSentinels($memo);

            $defined_classes_context = $this->collectClassTable(
                $class_table,
                $cg->map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            $analyzer->analyzeSingleLink('class_table', $defined_classes_context, $sink, null, $memo);
            unset($defined_classes_context);
            $context_pools->convertToSentinels($memo);

            $global_variables_context = $this->collectGlobalVariables(
                $eg->symbol_table,
                $cg->map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            $analyzer->analyzeSingleLink('global_variables', $global_variables_context, $sink, null, $memo);
            unset($global_variables_context);
            $context_pools->convertToSentinels($memo);

            $global_constants_context = $this->collectGlobalConstants(
                $zend_constants,
                $cg->map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            $analyzer->analyzeSingleLink('global_constants', $global_constants_context, $sink, null, $memo);
            unset($global_constants_context);
            $context_pools->convertToSentinels($memo);

            try {
                $global_callbacks_context = $this->collectGlobalCallbacks(
                    $eg,
                    $cg->map_ptr_base,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                    $memory_limit_error_details,
                );
            } catch (\Throwable $e) {
                Log::info('failed to collect global callbacks', ['error' => $e->getMessage()]);
                $global_callbacks_context = new GlobalCallbacksContext();
            }
            $analyzer->analyzeSingleLink('global_callbacks', $global_callbacks_context, $sink, null, $memo);
            unset($global_callbacks_context);
            $context_pools->convertToSentinels($memo);

            try {
                $modules_context = $this->collectModules(
                    $bg_address,
                    $cg->map_ptr_base,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                    $memory_limit_error_details,
                );
            } catch (\Throwable $e) {
                Log::info('failed to collect modules', ['error' => $e->getMessage()]);
                $modules_context = new ModulesContext();
            }
            $analyzer->analyzeSingleLink('modules', $modules_context, $sink, null, $memo);
            unset($modules_context);
            $context_pools->convertToSentinels($memo);

            $call_frames_context = $this->collectCallFrames(
                $eg,
                $cg->map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );

            if ($memory_limit_error_details and !is_null($this->memory_limit_error_function_context)) {
                $call_frames_context = $this->collectRealCallStackOnMemoryLimitViolation(
                    $this->memory_limit_error_function_context,
                    $memory_limit_error_details->max_challenge_depth,
                    $call_frames_context,
                    $eg,
                    $cg->map_ptr_base,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                );
            }

            $analyzer->analyzeSingleLink('call_frames', $call_frames_context, $sink, null, $memo);
            unset($call_frames_context);
            $context_pools->convertToSentinels($memo);

            // Resolve deferred edges from objects_store shallow collection.
            // By now most addresses have sentinels from all phases.
            // For remaining unresolved addresses (only reachable from
            // objects_store properties), collect them now with defer off.
            $this->defer_unseen_objects = false;
            $remaining_deferred = [];
            foreach ($this->deferred_object_edges as [$parent_node_id, $child_address, $link_name, $pointer_type]) {
                $child_sentinel = $context_pools->getSentinel($child_address);
                if ($child_sentinel !== null) {
                    $sink->emitReference($child_sentinel->node_id, $parent_node_id, $link_name);
                    continue;
                }
                // Not resolved — collect it now
                $remaining_deferred[] = [$parent_node_id, $child_address, $link_name, $pointer_type];
            }
            $this->deferred_object_edges = [];

            // Collect unresolved deferred targets (arrays/objects/references
            // only reachable from object properties). Defer is off, so they
            // will be fully expanded. Each is emitted as a child of the
            // original parent.
            foreach ($remaining_deferred as [$parent_node_id, $child_address, $link_name, $pointer_type]) {
                $child_sentinel = $context_pools->getSentinel($child_address);
                if ($child_sentinel !== null) {
                    $sink->emitReference($child_sentinel->node_id, $parent_node_id, $link_name);
                    continue;
                }
                $child_context = match ($pointer_type) {
                    'array' => $this->collectZendArrayPointer(
                        new Pointer(ZendArray::class, $child_address, $zend_type_reader->sizeOf('zend_array')),
                        $cg->map_ptr_base, $memory_locations, $dereferencer,
                        $zend_type_reader, $context_pools, $memory_limit_error_details,
                    ),
                    'object' => $this->collectZendObjectPointer(
                        new Pointer(ZendObject::class, $child_address, $zend_type_reader->sizeOf('zend_object')),
                        $cg->map_ptr_base, $memory_locations, $dereferencer,
                        $zend_type_reader, $context_pools, $memory_limit_error_details,
                    ),
                    'reference' => $this->collectPhpReferencePointer(
                        new Pointer(ZendReference::class, $child_address, $zend_type_reader->sizeOf('zend_reference')),
                        $cg->map_ptr_base, $memory_locations, $dereferencer,
                        $zend_type_reader, $context_pools, $memory_limit_error_details,
                    ),
                    default => null,
                };
                if ($child_context !== null) {
                    $analyzer->analyzeSingleLink($link_name, $child_context, $sink, $parent_node_id, $memo);
                    $context_pools->convertToSentinels($memo);
                }
            }

            $context_pools->clear();

            // Clear streaming state
            $this->streaming_sink = null;
            $this->streaming_analyzer = null;
            $this->streaming_memo = null;
            $this->streaming_context_pools = null;

            return new CollectedMemories(
                $chunk_memory_locations,
                $huge_memory_locations,
                $vm_stack_memory_locations,
                $compiler_arena_memory_locations,
                $cached_chunks_size,
                $memory_locations,
                null,
                $memory_get_usage_size,
                $memory_get_usage_real_size,
                $memory_get_peak_usage,
                $memory_limit,
            );
        }

        // Non-streaming path: original collection order (0.12.x compatible).
        // call_frames → function_table → class_table → ... → objects_store
        $global_variables_context = $this->collectGlobalVariables(
            $eg->symbol_table,
            $cg->map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );

        $call_frames_context = $this->collectCallFrames(
            $eg,
            $cg->map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );

        $defined_functions_context = $this->collectFunctionTable(
            $function_table,
            $cg->map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );

        $defined_classes_context = $this->collectClassTable(
            $class_table,
            $cg->map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );

        $global_constants_context = $this->collectGlobalConstants(
            $zend_constants,
            $cg->map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );

        try {
            $global_callbacks_context = $this->collectGlobalCallbacks(
                $eg,
                $cg->map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
        } catch (\Throwable $e) {
            Log::info('failed to collect global callbacks', ['error' => $e->getMessage()]);
            $global_callbacks_context = new GlobalCallbacksContext();
        }

        try {
            $modules_context = $this->collectModules(
                $bg_address,
                $cg->map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
        } catch (\Throwable $e) {
            Log::info('failed to collect modules', ['error' => $e->getMessage()]);
            $modules_context = new ModulesContext();
        }

        $objects_store_context = $this->collectObjectsStore(
            $eg->objects_store,
            $cg->map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );

        if ($memory_limit_error_details and !is_null($this->memory_limit_error_function_context)) {
            $call_frames_context = $this->collectRealCallStackOnMemoryLimitViolation(
                $this->memory_limit_error_function_context,
                $memory_limit_error_details->max_challenge_depth,
                $call_frames_context,
                $eg,
                $cg->map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
            );
        }

        $top_reference_context = new TopReferenceContext(
            $call_frames_context,
            $global_variables_context,
            $defined_functions_context,
            $defined_classes_context,
            $global_constants_context,
            $included_files_context,
            $interned_strings_context,
            $objects_store_context,
            $global_callbacks_context,
            $modules_context,
        );

        // Release pool references so ContextAnalyzer's releaseLinks() can trigger GC
        $context_pools->clear();

        return new CollectedMemories(
            $chunk_memory_locations,
            $huge_memory_locations,
            $vm_stack_memory_locations,
            $compiler_arena_memory_locations,
            $cached_chunks_size,
            $memory_locations,
            $top_reference_context,
            $memory_get_usage_size,
            $memory_get_usage_real_size,
            $memory_get_peak_usage,
            $memory_limit,
        );
    }

    public function collectZval(
        Zval $zval,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ?ReferenceContext {
        if ($zval->isArray()) {
            assert(!is_null($zval->value->arr));
            return $this->collectZendArrayPointer(
                $zval->value->arr,
                $map_ptr_base,
                $memory_locations,
                $dereferencer,
                $zend_type_reader,
                $context_pools,
                $memory_limit_error_details,
            );
        } elseif ($zval->isObject()) {
            if ($zval->value->obj === null) {
                return null;
            }
            return $this->collectZendObjectPointer(
                $zval->value->obj,
                $map_ptr_base,
                $memory_locations,
                $dereferencer,
                $zend_type_reader,
                $context_pools,
                $memory_limit_error_details,
            );
        } elseif ($zval->isString()) {
            assert(!is_null($zval->value->str));
            return $this->collectZendStringPointer(
                $zval->value->str,
                $memory_locations,
                $dereferencer,
                $context_pools,
            );
        } elseif (
            $zval->isBool()
            or $zval->isLong()
            or $zval->isDouble()
            or $zval->isNull()
        ) {
            return match ($zval->getType()) {
                'IS_TRUE' => new ScalarValueContext(true),
                'IS_FALSE' => new ScalarValueContext(false),
                'IS_LONG' => new ScalarValueContext($zval->value->lval),
                'IS_DOUBLE' => new ScalarValueContext($zval->value->dval),
                'IS_NULL' => new ScalarValueContext(null),
            };
        } elseif ($zval->isReference()) {
            assert(!is_null($zval->value->ref));
            return $this->collectPhpReferencePointer(
                $zval->value->ref,
                $map_ptr_base,
                $memory_locations,
                $dereferencer,
                $zend_type_reader,
                $context_pools,
                $memory_limit_error_details,
            );
        } elseif ($zval->isResource()) {
            assert(!is_null($zval->value->res));
            return $this->collectResourcePointer(
                $zval->value->res,
                $memory_locations,
                $dereferencer,
                $zend_type_reader,
                $context_pools,
            );
        } elseif ($zval->isIndirect()) {
            $zval = $dereferencer->deref(
                $zval->value->getAsPointer(Zval::class, $zend_type_reader->sizeOf('zval'))
            );
            return $this->collectZval(
                $zval,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
        }
        return null;
    }

    /** @param Pointer<ZendResource> $pointer */
    public function collectResourcePointer(
        Pointer $pointer,
        MemoryLocations $memory_locations,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        ContextPools $context_pools
    ): ResourceContext|SentinelContext {
        if ($memory_locations->has($pointer->address)) {
            $sentinel = $context_pools->getSentinel($pointer->address);
            if ($sentinel !== null) {
                return $sentinel;
            }
            $cached = $context_pools->resource_context_pool->getContextByAddress($pointer->address);
            if ($cached !== null) {
                return $cached;
            }
        }
        $resource = $dereferencer->deref($pointer);
        $memory_location = ZendResourceMemoryLocation::fromZendReference($resource);
        $memory_locations->add($memory_location);
        $resource_context = $context_pools
            ->resource_context_pool
            ->getContextForLocation($memory_location)
        ;
        $this->tryCollectStreamData(
            $resource,
            $pointer,
            $memory_locations,
            $dereferencer,
            $zend_type_reader,
            $context_pools,
            $resource_context,
        );
        return $resource_context;
    }

    private function tryCollectStreamData(
        ZendResource $resource,
        Pointer $resource_pointer,
        MemoryLocations $memory_locations,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        ContextPools $context_pools,
        ResourceContext $resource_context,
    ): void {
        $ptr_address = $resource->ptr;
        if ($ptr_address === 0) {
            return;
        }

        try {
            $php_stream_pointer = new Pointer(
                PhpStream::class,
                $ptr_address,
                $zend_type_reader->sizeOf('php_stream'),
            );
            $php_stream = $dereferencer->deref($php_stream_pointer);

            // Validate: php_stream.res should point back to the zend_resource
            if ($php_stream->res !== $resource_pointer->address) {
                return;
            }

            // Read ops->label to determine stream type
            $ops_address = $php_stream->ops;
            if ($ops_address === 0) {
                return;
            }
            $ops_pointer = new Pointer(
                PhpStreamOps::class,
                $ops_address,
                $zend_type_reader->sizeOf('php_stream_ops'),
            );
            $ops = $dereferencer->deref($ops_pointer);

            $label_address = $ops->label;
            if ($label_address === 0) {
                return;
            }
            $label_pointer = new Pointer(
                RawString::class,
                $label_address,
                32,
            );
            $label = (string)$dereferencer->deref($label_pointer);

            $resource_context->stream_type_label = $label;

            // For MEMORY streams, collect the zend_string data buffer
            if ($label === 'MEMORY') {
                $this->collectMemoryStreamData(
                    $php_stream,
                    $memory_locations,
                    $dereferencer,
                    $zend_type_reader,
                    $context_pools,
                    $resource_context,
                );
            } elseif ($label === 'TEMP') {
                $this->collectTempStreamData(
                    $php_stream,
                    $memory_locations,
                    $dereferencer,
                    $zend_type_reader,
                    $context_pools,
                    $resource_context,
                );
            }
        } catch (\Throwable) {
            // Reading stream data is best-effort; if the memory is
            // unmapped or the layout doesn't match, just skip it.
            return;
        }
    }

    private function collectMemoryStreamData(
        PhpStream $php_stream,
        MemoryLocations $memory_locations,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        ContextPools $context_pools,
        ResourceContext $resource_context,
    ): void {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return;
        }

        $mem_data_pointer = new Pointer(
            PhpStreamMemoryData::class,
            $abstract_address,
            $zend_type_reader->sizeOf('php_stream_memory_data'),
        );
        $mem_data = $dereferencer->deref($mem_data_pointer);

        $data_address = $mem_data->data;
        if ($data_address === 0) {
            return;
        }

        $string_pointer = new Pointer(
            ZendString::class,
            $data_address,
            $zend_type_reader->sizeOf('zend_string'),
        );
        $string_context = $this->collectZendStringPointer(
            $string_pointer,
            $memory_locations,
            $dereferencer,
            $context_pools,
        );
        $resource_context->add('stream_memory_data', $string_context);
    }

    private function collectTempStreamData(
        PhpStream $php_stream,
        MemoryLocations $memory_locations,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        ContextPools $context_pools,
        ResourceContext $resource_context,
    ): void {
        $abstract_address = $php_stream->abstract;
        if ($abstract_address === 0) {
            return;
        }

        $temp_data_pointer = new Pointer(
            PhpStreamTempData::class,
            $abstract_address,
            $zend_type_reader->sizeOf('php_stream_temp_data'),
        );
        $temp_data = $dereferencer->deref($temp_data_pointer);

        $innerstream_address = $temp_data->innerstream;
        if ($innerstream_address === 0) {
            return;
        }

        // Read the inner stream to check if it's a MEMORY stream
        $inner_pointer = new Pointer(
            PhpStream::class,
            $innerstream_address,
            $zend_type_reader->sizeOf('php_stream'),
        );
        $inner_stream = $dereferencer->deref($inner_pointer);

        $inner_ops_address = $inner_stream->ops;
        if ($inner_ops_address === 0) {
            return;
        }
        $inner_ops_pointer = new Pointer(
            PhpStreamOps::class,
            $inner_ops_address,
            $zend_type_reader->sizeOf('php_stream_ops'),
        );
        $inner_ops = $dereferencer->deref($inner_ops_pointer);

        $inner_label_address = $inner_ops->label;
        if ($inner_label_address === 0) {
            return;
        }
        $inner_label_pointer = new Pointer(
            RawString::class,
            $inner_label_address,
            32,
        );
        $inner_label = (string)$dereferencer->deref($inner_label_pointer);

        if ($inner_label === 'MEMORY') {
            $this->collectMemoryStreamData(
                $inner_stream,
                $memory_locations,
                $dereferencer,
                $zend_type_reader,
                $context_pools,
                $resource_context,
            );
        }
    }


    private function collectPdoDbhObject(
        ZendObject $object,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ObjectContext $object_context,
    ): void {
        // pdo_dbh_object_t: { pdo_dbh_t *inner; zend_object std; }
        // The inner pointer is at the start of pdo_dbh_object_t, which is
        // std_offset bytes before the zend_object address.
        [$std_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_object_t', 'std');
        $inner_ptr_address = $object->getPointer()->address - $std_offset;

        // Read the pdo_dbh_t pointer (8 bytes at inner_ptr_address)
        $inner_ptr = new Pointer(RawInt64::class, $inner_ptr_address, 8);
        $inner_address = $dereferencer->deref($inner_ptr)->value;
        if ($inner_address === 0) {
            return;
        }

        // Track pdo_dbh_t allocation
        $dbh_size = $zend_type_reader->sizeOf('pdo_dbh_t');
        $dbh_location = new PdoDbhMemoryLocation($inner_address, $dbh_size);
        $memory_locations->add($dbh_location);

        // Detect driver from data_source (DSN prefix: "sqlite:", "pgsql:", "mysql:")
        $driver_name = $this->detectPdoDriver(
            $inner_address,
            $dereferencer,
            $zend_type_reader,
        );

        // Read driver_data pointer from pdo_dbh_t via offset
        [$dd_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_t', 'driver_data');
        $dd_ptr = new Pointer(RawInt64::class, $inner_address + $dd_offset, 8);
        $driver_data_address = $dereferencer->deref($dd_ptr)->value;

        if ($driver_data_address !== 0) {
            $this->collectPdoDriverData(
                $driver_name,
                $driver_data_address,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
            );
        }

        // Track data_source, username, password strings (pestrdup allocations)
        $this->collectPdoDbhStrings(
            $inner_address,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
        );
    }

    private function collectPdoDbhStrings(
        int $dbh_address,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
    ): void {
        // data_source: read address and length
        try {
            [$ds_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_t', 'data_source');
            $ds_ptr = new Pointer(RawInt64::class, $dbh_address + $ds_offset, 8);
            $ds_address = $dereferencer->deref($ds_ptr)->value;

            [$dsl_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_t', 'data_source_len');
            $dsl_ptr = new Pointer(RawInt64::class, $dbh_address + $dsl_offset, 8);
            $ds_len = $dereferencer->deref($dsl_ptr)->value;

            if ($ds_address !== 0 && $ds_len > 0 && $ds_len < 65536) {
                $memory_locations->add(new PdoDbhMemoryLocation($ds_address, $ds_len + 1));
            }
        } catch (\Throwable) {
        }

        // username and password: read address, measure with RawString
        foreach (['username', 'password'] as $field) {
            try {
                [$f_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_t', $field);
                $f_ptr = new Pointer(RawInt64::class, $dbh_address + $f_offset, 8);
                $f_address = $dereferencer->deref($f_ptr)->value;
                if ($f_address !== 0 && !$memory_locations->has($f_address)) {
                    // Read up to 256 bytes to find string length
                    $raw_ptr = new Pointer(RawString::class, $f_address, 256);
                    $str = (string)$dereferencer->deref($raw_ptr);
                    $len = strlen($str);
                    if ($len > 0) {
                        $memory_locations->add(new PdoDbhMemoryLocation($f_address, $len + 1));
                    }
                }
            } catch (\Throwable) {
            }
        }
    }

    private function collectPdoStmt(
        ZendObject $object,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ObjectContext $object_context,
    ): void {
        // pdo_stmt_t has zend_object std at the end; read individual fields
        // by offset to avoid FFI issues with the embedded flexible array.
        [$std_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', 'std');
        $stmt_address = $object->getPointer()->address - $std_offset;

        // Read query_string and active_query_string (v81+: zend_string *)
        if (!$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V81)) {
            foreach (['query_string', 'active_query_string'] as $idx => $field) {
                try {
                    [$qs_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', $field);
                    $qs_ptr = new Pointer(RawInt64::class, $stmt_address + $qs_offset, 8);
                    $qs_address = $dereferencer->deref($qs_ptr)->value;
                    if ($qs_address !== 0) {
                        $string_pointer = new Pointer(
                            ZendString::class,
                            $qs_address,
                            $zend_type_reader->sizeOf('zend_string'),
                        );
                        $string_context = $this->collectZendStringPointer(
                            $string_pointer,
                            $memory_locations,
                            $dereferencer,
                            $context_pools,
                        );
                        if ($idx === 0) {
                            $object_context->add('pdo_query_string', $string_context);
                        }
                    }
                } catch (\Throwable) {
                }
            }
        }

        // Track bound_params, bound_param_map, bound_columns HashTables
        foreach (['bound_params', 'bound_param_map', 'bound_columns'] as $ht_field) {
            try {
                [$ht_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', $ht_field);
                $ht_ptr = new Pointer(RawInt64::class, $stmt_address + $ht_offset, 8);
                $ht_address = $dereferencer->deref($ht_ptr)->value;
                if ($ht_address !== 0 && !$memory_locations->has($ht_address)) {
                    $ht_pointer = new Pointer(
                        ZendArray::class,
                        $ht_address,
                        $zend_type_reader->sizeOf('zend_array'),
                    );
                    $this->collectZendArray(
                        $dereferencer->deref($ht_pointer),
                        0,
                        $dereferencer,
                        $zend_type_reader,
                        $memory_locations,
                        $context_pools,
                        null,
                    );
                }
            } catch (\Throwable) {
            }
        }

        // Detect driver via pdo_stmt_t.dbh -> pdo_dbh_t.data_source
        [$dbh_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', 'dbh');
        $dbh_ptr = new Pointer(RawInt64::class, $stmt_address + $dbh_offset, 8);
        $dbh_address = $dereferencer->deref($dbh_ptr)->value;

        $driver_name = '';
        if ($dbh_address !== 0) {
            $driver_name = $this->detectPdoDriver(
                $dbh_address,
                $dereferencer,
                $zend_type_reader,
            );
        }

        // Read driver_data pointer
        [$dd_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', 'driver_data');
        $dd_ptr = new Pointer(RawInt64::class, $stmt_address + $dd_offset, 8);
        $driver_data_address = $dereferencer->deref($dd_ptr)->value;
        if ($driver_data_address !== 0) {
            $this->collectPdoStmtDriverData(
                $driver_name,
                $driver_data_address,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
            );
        }

        // Read column_count and columns pointer for column name tracking
        [$cc_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', 'column_count');
        $cc_ptr = new Pointer(RawInt64::class, $stmt_address + $cc_offset, 4);
        // Read 4-byte int as raw bytes
        $cc_buf = $dereferencer->deref(new Pointer(RawInt64::class, $stmt_address + $cc_offset, 8));
        $column_count = $cc_buf->value & 0xFFFFFFFF;
        if ($column_count > 0x7FFFFFFF) {
            $column_count = 0; // negative or invalid
        }

        [$cols_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', 'columns');
        $cols_ptr = new Pointer(RawInt64::class, $stmt_address + $cols_offset, 8);
        $columns_address = $dereferencer->deref($cols_ptr)->value;

        if ($columns_address !== 0 && $column_count > 0 && $column_count < 10000) {
            $column_data_size = $zend_type_reader->sizeOf('pdo_column_data');

            // Track the columns array allocation: ecalloc(column_count, sizeof(pdo_column_data))
            if (!$memory_locations->has($columns_address)) {
                $memory_locations->add(
                    new PdoDriverDataMemoryLocation($columns_address, (int)$column_count * $column_data_size)
                );
            }

            for ($i = 0; $i < $column_count; $i++) {
                $col_pointer = new Pointer(
                    PdoColumnData::class,
                    $columns_address + $i * $column_data_size,
                    $column_data_size,
                );
                $col = $dereferencer->deref($col_pointer);
                $name_address = $col->name;
                if ($name_address !== 0) {
                    $name_pointer = new Pointer(
                        ZendString::class,
                        $name_address,
                        $zend_type_reader->sizeOf('zend_string'),
                    );
                    $this->collectZendStringPointer(
                        $name_pointer,
                        $memory_locations,
                        $dereferencer,
                        $context_pools,
                    );
                }
            }
        }
    }

    private function detectPdoDriver(
        int $dbh_address,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): string {
        try {
            // Read pdo_dbh_t.driver -> pdo_driver_t, then read driver_name
            // pdo_driver_t: { const char *driver_name; size_t driver_name_len; ... }
            [$drv_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_t', 'driver');
            $drv_ptr = new Pointer(RawInt64::class, $dbh_address + $drv_offset, 8);
            $driver_struct_address = $dereferencer->deref($drv_ptr)->value;
            if ($driver_struct_address === 0) {
                return '';
            }
            // driver_name is the first field of pdo_driver_t (a char* pointer)
            $name_ptr_ptr = new Pointer(RawInt64::class, $driver_struct_address, 8);
            $name_address = $dereferencer->deref($name_ptr_ptr)->value;
            if ($name_address === 0) {
                return '';
            }
            $name_pointer = new Pointer(RawString::class, $name_address, 16);
            return (string)$dereferencer->deref($name_pointer);
        } catch (\Throwable) {
            return '';
        }
    }

    /** @var array<string, array{class-string, string}> driver -> [dbh_class, ctype] */
    private const PDO_DRIVER_DBH_MAP = [
        'sqlite' => [PdoSqliteDbHandle::class, 'pdo_sqlite_db_handle'],
        'pgsql' => [PdoPgsqlDbHandle::class, 'pdo_pgsql_db_handle'],
        'mysql' => [PdoMysqlDbHandle::class, 'pdo_mysql_db_handle'],
    ];

    /** @var array<string, array{class-string, string}> driver -> [stmt_class, ctype] */
    private const PDO_DRIVER_STMT_MAP = [
        'sqlite' => [PdoSqliteStmt::class, 'pdo_sqlite_stmt'],
        'pgsql' => [PdoPgsqlStmt::class, 'pdo_pgsql_stmt'],
        'mysql' => [PdoMysqlStmt::class, 'pdo_mysql_stmt'],
    ];

    private function collectPdoDriverData(
        string $driver_name,
        int $driver_data_address,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
    ): void {
        $map = self::PDO_DRIVER_DBH_MAP[$driver_name] ?? null;
        if ($map === null) {
            return;
        }
        [$class, $ctype] = $map;
        try {
            $size = $zend_type_reader->sizeOf($ctype);
            $dereferencer->deref(new Pointer($class, $driver_data_address, $size));
            $memory_locations->add(new PdoDriverDataMemoryLocation($driver_data_address, $size));
        } catch (\Throwable) {
        }
    }

    private function collectPdoStmtDriverData(
        string $driver_name,
        int $driver_data_address,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
    ): void {
        $map = self::PDO_DRIVER_STMT_MAP[$driver_name] ?? null;
        if ($map === null) {
            return;
        }
        [$class, $ctype] = $map;
        try {
            $size = $zend_type_reader->sizeOf($ctype);
            $dereferencer->deref(new Pointer($class, $driver_data_address, $size));
            $memory_locations->add(new PdoDriverDataMemoryLocation($driver_data_address, $size));
        } catch (\Throwable) {
            return;
        }

        // For MySQL (mysqlnd), follow the result pointer to track buffered result data
        if ($driver_name === 'mysql') {
            $this->collectMysqlndResultFromPdoMysqlStmt(
                $driver_data_address,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
            );
        }
    }

    private function collectMysqlndResultFromPdoMysqlStmt(
        int $pdo_mysql_stmt_address,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
    ): void {
        try {
            // pdo_mysql_stmt.result is the 2nd pointer field (after H)
            $result_ptr = new Pointer(
                RawInt64::class,
                $pdo_mysql_stmt_address + 8, // offset of result (after H pointer)
                8,
            );
            $result_address = $dereferencer->deref($result_ptr)->value;
            if ($result_address === 0) {
                return;
            }

            $this->collectMysqlndResult(
                $result_address,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
            );
        } catch (\Throwable) {
        }
    }

    private function collectMysqlndResult(
        int $res_address,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
    ): void {
        if ($memory_locations->has($res_address)) {
            return;
        }

        $res_size = $zend_type_reader->sizeOf('MYSQLND_RES');
        try {
            $dereferencer->deref(new Pointer(MysqlndRes::class, $res_address, $res_size));
        } catch (\Throwable) {
            return;
        }
        $memory_locations->add(new MysqlndMemoryLocation($res_address, $res_size));

        // Read stored_data pointer (MYSQLND_RES_BUFFERED *)
        [$sd_offset] = $zend_type_reader->getOffsetAndSizeOfMember('MYSQLND_RES', 'stored_data');
        $sd_ptr = new Pointer(RawInt64::class, $res_address + $sd_offset, 8);
        $stored_data_address = $dereferencer->deref($sd_ptr)->value;

        if ($stored_data_address !== 0) {
            $this->collectMysqlndResBuffered(
                $stored_data_address,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
            );
        }

        // Read memory_pool pointer
        [$mp_offset] = $zend_type_reader->getOffsetAndSizeOfMember('MYSQLND_RES', 'memory_pool');
        $mp_ptr = new Pointer(RawInt64::class, $res_address + $mp_offset, 8);
        $mempool_address = $dereferencer->deref($mp_ptr)->value;

        if ($mempool_address !== 0 && !$memory_locations->has($mempool_address)) {
            $mp_size = $zend_type_reader->sizeOf('MYSQLND_MEMORY_POOL');
            try {
                $memory_locations->add(new MysqlndMemoryLocation($mempool_address, $mp_size));
            } catch (\Throwable) {
            }
        }
    }

    private function collectMysqlndResBuffered(
        int $buf_address,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
    ): void {
        if ($memory_locations->has($buf_address)) {
            return;
        }

        $buf_size = $zend_type_reader->sizeOf('MYSQLND_RES_BUFFERED');
        try {
            $dereferencer->deref(new Pointer(MysqlndResBuffered::class, $buf_address, $buf_size));
        } catch (\Throwable) {
            return;
        }
        $memory_locations->add(new MysqlndMemoryLocation($buf_address, $buf_size));

        // Read row_buffers array pointer and row_count
        [$rb_offset] = $zend_type_reader->getOffsetAndSizeOfMember('MYSQLND_RES_BUFFERED', 'row_buffers');
        $rb_ptr = new Pointer(RawInt64::class, $buf_address + $rb_offset, 8);
        $row_buffers_address = $dereferencer->deref($rb_ptr)->value;

        [$rc_offset] = $zend_type_reader->getOffsetAndSizeOfMember('MYSQLND_RES_BUFFERED', 'row_count');
        $rc_ptr = new Pointer(RawInt64::class, $buf_address + $rc_offset, 8);
        $row_count = $dereferencer->deref($rc_ptr)->value;

        // Track the row_buffers array (MYSQLND_ROW_BUFFER[row_count])
        if ($row_buffers_address !== 0 && $row_count > 0 && $row_count < 1000000) {
            $rb_element_size = $zend_type_reader->sizeOf('MYSQLND_ROW_BUFFER');
            $total_size = (int)($row_count * $rb_element_size);
            if (!$memory_locations->has($row_buffers_address)) {
                $memory_locations->add(new MysqlndMemoryLocation($row_buffers_address, $total_size));
            }
        }

        // Read result_set_memory_pool
        [$rmp_offset] = $zend_type_reader->getOffsetAndSizeOfMember('MYSQLND_RES_BUFFERED', 'result_set_memory_pool');
        $rmp_ptr = new Pointer(RawInt64::class, $buf_address + $rmp_offset, 8);
        $rmp_address = $dereferencer->deref($rmp_ptr)->value;

        if ($rmp_address !== 0 && !$memory_locations->has($rmp_address)) {
            $mp_size = $zend_type_reader->sizeOf('MYSQLND_MEMORY_POOL');
            $memory_locations->add(new MysqlndMemoryLocation($rmp_address, $mp_size));

            // Follow arena pointer to track arena chunks
            $arena_ptr = new Pointer(RawInt64::class, $rmp_address, 8);
            $arena_address = $dereferencer->deref($arena_ptr)->value;
            $this->collectZendArenaChain($arena_address, $memory_locations, $dereferencer);
        }
    }

    private function collectZendArenaChain(
        int $arena_address,
        MemoryLocations $memory_locations,
        Dereferencer $dereferencer,
    ): void {
        // zend_arena: { char *ptr; char *end; zend_arena *prev; }
        // Walk the linked list of arena chunks
        $visited = 0;
        while ($arena_address !== 0 && $visited < 1000) {
            if ($memory_locations->has($arena_address)) {
                break;
            }
            try {
                // Read end pointer to determine arena size: end - arena_address = total chunk size
                $end_ptr = new Pointer(RawInt64::class, $arena_address + 8, 8);
                $end_address = $dereferencer->deref($end_ptr)->value;

                if ($end_address > $arena_address) {
                    $chunk_size = $end_address - $arena_address;
                    if ($chunk_size < 64 * 1024 * 1024) { // sanity: max 64MB per chunk
                        $memory_locations->add(new MysqlndMemoryLocation($arena_address, (int)$chunk_size));
                    }
                }

                // Read prev pointer
                $prev_ptr = new Pointer(RawInt64::class, $arena_address + 16, 8);
                $arena_address = $dereferencer->deref($prev_ptr)->value;
                $visited++;
            } catch (\Throwable) {
                break;
            }
        }
    }

    /** @param Pointer<ZendReference> $pointer */
    public function collectPhpReferencePointer(
        Pointer $pointer,
        int $map_ptr_base,
        MemoryLocations $memory_locations,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): PhpReferenceContext|SentinelContext|null {
        if ($memory_locations->has($pointer->address)) {
            $sentinel = $context_pools->getSentinel($pointer->address);
            if ($sentinel !== null) {
                return $sentinel;
            }
            $cached = $context_pools->php_reference_context_pool->getContextByAddress($pointer->address);
            if ($cached !== null) {
                return $cached;
            }
            if ($this->defer_unseen_objects) {
                if ($this->current_streaming_parent_node_id !== null) {
                    $this->deferred_object_edges[] = [
                        $this->current_streaming_parent_node_id,
                        $pointer->address,
                        $this->current_streaming_link_name ?? 'deferred_reference',
                        'reference',
                    ];
                }
                return null;
            }
        } elseif ($this->defer_unseen_objects) {
            if ($this->current_streaming_parent_node_id !== null) {
                $this->deferred_object_edges[] = [
                    $this->current_streaming_parent_node_id,
                    $pointer->address,
                    $this->current_streaming_link_name ?? 'deferred_reference',
                    'reference',
                ];
            }
            return null;
        }
        $php_reference = $dereferencer->deref($pointer);
        $memory_location = ZendReferenceMemoryLocation::fromZendReference($php_reference);
        $memory_locations->add($memory_location);
        $php_referencecontext = $context_pools
            ->php_reference_context_pool
            ->getContextForLocation($memory_location)
        ;
        $zval_context = $this->collectZval(
            $php_reference->val,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
        if (!is_null($zval_context)) {
            $php_referencecontext->add('referenced', $zval_context);
        }
        return $php_referencecontext;
    }

    /** @param Pointer<ZendArray> $pointer */
    public function collectZendArrayPointer(
        Pointer $pointer,
        int $map_ptr_base,
        MemoryLocations $memory_locations,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ArrayHeaderContext|SentinelContext|null {
        if ($memory_locations->has($pointer->address)) {
            $sentinel = $context_pools->getSentinel($pointer->address);
            if ($sentinel !== null) {
                return $sentinel;
            }
            $cached = $context_pools->array_context_pool->getContextByAddress($pointer->address);
            if ($cached !== null) {
                return $cached;
            }
            if ($this->defer_unseen_objects) {
                if ($this->current_streaming_parent_node_id !== null) {
                    $this->deferred_object_edges[] = [
                        $this->current_streaming_parent_node_id,
                        $pointer->address,
                        $this->current_streaming_link_name ?? 'deferred_array',
                        'array',
                    ];
                }
                return null;
            }
        } elseif ($this->defer_unseen_objects) {
            // During shallow collection: don't recurse into unseen arrays.
            // Record a deferred edge if we have a parent context.
            if ($this->current_streaming_parent_node_id !== null) {
                $this->deferred_object_edges[] = [
                    $this->current_streaming_parent_node_id,
                    $pointer->address,
                    $this->current_streaming_link_name ?? 'deferred_array',
                    'array',
                ];
            }
            return null;
        }
        $array = $dereferencer->deref($pointer);
        return $this->collectZendArray(
            $array,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
    }

    /** @param Pointer<ZendObject> $pointer */
    public function collectZendObjectPointer(
        Pointer $pointer,
        int $map_ptr_base,
        MemoryLocations $memory_locations,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ObjectContext|SentinelContext|null {
        if ($memory_locations->has($pointer->address)) {
            $sentinel = $context_pools->getSentinel($pointer->address);
            if ($sentinel !== null) {
                return $sentinel;
            }
            $cached = $context_pools->object_context_pool->getContextByAddress($pointer->address);
            if ($cached !== null) {
                return $cached;
            }
            if ($this->defer_unseen_objects) {
                if ($this->current_streaming_parent_node_id !== null) {
                    $this->deferred_object_edges[] = [
                        $this->current_streaming_parent_node_id,
                        $pointer->address,
                        $this->current_streaming_link_name ?? 'deferred_object',
                        'object',
                    ];
                }
                return null;
            }
        } elseif ($this->defer_unseen_objects) {
            // During objects_store collection: don't recurse into unseen
            // objects via property traversal. Record the edge so it can
            // be emitted after all objects are collected.
            if ($this->current_streaming_parent_node_id !== null) {
                $this->deferred_object_edges[] = [
                    $this->current_streaming_parent_node_id,
                    $pointer->address,
                    $this->current_streaming_link_name ?? 'deferred_object',
                    'object',
                ];
            }
            return null;
        }
        $obj = $dereferencer->deref($pointer);
        return $this->collectZendObject(
            $obj,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
    }

    /** @param Pointer<ZendString> $pointer */
    public function collectZendStringPointer(
        Pointer $pointer,
        MemoryLocations $memory_locations,
        Dereferencer $dereferencer,
        ContextPools $context_pools
    ): StringContext|SentinelContext {
        if ($memory_locations->has($pointer->address)) {
            $sentinel = $context_pools->getSentinel($pointer->address);
            if ($sentinel !== null) {
                return $sentinel;
            }
            $cached = $context_pools->string_context_pool->getContextByAddress($pointer->address);
            if ($cached !== null) {
                return $cached;
            }
        }
        $str = $dereferencer->deref($pointer);
        $memory_location = ZendStringMemoryLocation::fromZendString(
            $str,
            $dereferencer,
        );
        $memory_locations->add($memory_location);
        return $context_pools
            ->string_context_pool
            ->getContextForLocation($memory_location)
        ;
    }

    public function collectCallFrames(
        ZendExecutorGlobals $eg,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): CallFramesContext {
        $call_frames_context = new CallFramesContext();
        $parent_node_id = $this->registerParentIfStreaming($call_frames_context);
        if (is_null($eg->current_execute_data)) {
            return $call_frames_context;
        }
        $execute_data = $dereferencer->deref($eg->current_execute_data);
        foreach ($execute_data->iterateStackChain($dereferencer) as $key => $execute_data) {
            $call_frame_context = $this->collectCallFrame(
                $execute_data,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if ($parent_node_id !== null) {
                $call_frame_context = $this->emitChildIfStreaming(
                    (string)$key,
                    $call_frame_context,
                    $parent_node_id,
                );
                $this->flushPoolsIfStreaming();
            }
            $call_frames_context->add((string)$key, $call_frame_context);
        }
        return $call_frames_context;
    }

    public function collectRealCallStackOnMemoryLimitViolation(
        UserFunctionDefinitionContext $memory_limit_error_function_context,
        int $max_challenge_depth,
        CallFramesContext $call_frames_context,
        ZendExecutorGlobals $eg,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
    ): CallFramesContext {
        $op_array_address = $memory_limit_error_function_context->getOpArrayAddress();

        if (is_null($eg->vm_stack)) {
            return $call_frames_context;
        }
        if (is_null($eg->vm_stack_top)) {
            return $call_frames_context;
        }

        $last_vm_stack = $dereferencer->deref($eg->vm_stack);
        $root_vm_stack = $last_vm_stack->getRootStack($dereferencer);
        if (is_null($root_vm_stack->top)) {
            return $call_frames_context;
        }

        $first_stack = true;
        foreach ($last_vm_stack->iterateStackChain($dereferencer) as $vm_stack) {
            if ($first_stack) {
                $first_stack = false;
                $stack_end_address = $eg->vm_stack_top->address;
            } else {
                if (is_null($vm_stack->end)) {
                    break;
                }
                $stack_end_address = $vm_stack->end->address;
            }
            $materialized_vm_stack = $vm_stack->materializeAsPointerArray(
                $dereferencer,
                $stack_end_address
            );
            foreach ($materialized_vm_stack->getReverseIteratorAsInt() as $key => $value) {
                if ($value !== $op_array_address) {
                    continue;
                }
                $pointer_address = $key * 8 + $materialized_vm_stack->getPointer()->address - 24;
                Log::debug('candidate frame found', ['frame_address' => $pointer_address]);
                $frame_candidate = new Pointer(
                    ZendExecuteData::class,
                    $pointer_address,
                    $zend_type_reader->sizeOf('zend_execute_data')
                );
                try {
                    $execute_data_candidate = $dereferencer->deref($frame_candidate);
                    $root_execute_data_candidate = $execute_data_candidate->getRootFrame(
                        $dereferencer,
                        $max_challenge_depth,
                    );
                    if ($root_vm_stack->top->address !== $root_execute_data_candidate->getPointer()->address) {
                        continue;
                    }
                    Log::debug('root candidate frame found', ['frame_address' => $root_vm_stack->top->address]);
                    $frame_start = count($call_frames_context->getLinks());
                    foreach ($execute_data_candidate->iterateStackChain($dereferencer) as $frame_no => $execute_data) {
                        $call_frame_context = $this->collectCallFrame(
                            $execute_data,
                            $map_ptr_base,
                            $dereferencer,
                            $zend_type_reader,
                            $memory_locations,
                            $context_pools,
                            null,
                        );
                        $call_frames_context->add((string)($frame_no + $frame_start), $call_frame_context);
                    }
                    return $call_frames_context;
                } catch (\Throwable $e) {
                    Log::debug(
                        'failed to collect real call stack from this candidate',
                        [
                            'exception' => $e,
                            'frame_address' => $pointer_address
                        ]
                    );
                    continue;
                }
            }
        }
        return $call_frames_context;
    }

    public function collectCallFrame(
        ZendExecuteData $execute_data,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): CallFrameContext {
        $function_name = $execute_data->getFullyQualifiedFunctionName(
            $dereferencer,
            $zend_type_reader,
        );

        $lineno = -1;
        if ($execute_data->opline !== null and !$execute_data->isInternalCall($dereferencer)) {
            $opline = $dereferencer->deref($execute_data->opline);
            $lineno = $opline->lineno;
        }

        $call_frame_context = new CallFrameContext(
            $function_name,
            $lineno,
        );

        // Track the entire call frame (header + variable table) as a single
        // memory location. The execute_data header and variable table are part
        // of the same allocation (either on the VM stack or via a single
        // emalloc for generators). Tracking them separately would cause
        // allocation overhead to be calculated for each piece independently,
        // double-counting the bin slot overhead.
        try {
            $variable_table_pointer = $execute_data->getVariableTablePointer($dereferencer);
            $frame_end = $variable_table_pointer->address + $variable_table_pointer->size;
            $frame_size = $frame_end - $execute_data->getPointer()->address;
        } catch (\Throwable) {
            $frame_size = $execute_data->getPointer()->size;
        }
        $memory_locations->add(
            new CallFrameHeaderMemoryLocation(
                $execute_data->getPointer()->address,
                $frame_size,
            )
        );

        if ($execute_data->hasThis()) {
            $this_context = $this->collectZval(
                $execute_data->This,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if (!is_null($this_context)) {
                $call_frame_parent_node_id = $this->registerParentIfStreaming($call_frame_context);
                if ($call_frame_parent_node_id !== null) {
                    $this_context = $this->emitChildIfStreaming(
                        'this',
                        $this_context,
                        $call_frame_parent_node_id,
                    );
                    $this->flushPoolsIfStreaming();
                }
                $call_frame_context->add('this', $this_context);
            }
        }

        $has_local_variables = false;
        $variable_table_context = new CallFrameVariableTableContext();
        $variable_table_parent_node_id = $this->registerParentIfStreaming($variable_table_context);
        $local_variables_iterator = $execute_data->getVariables($dereferencer, $zend_type_reader);
        foreach ($local_variables_iterator as $name => $value) {
            $local_variable_context = $this->collectZval(
                $value,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if (!is_null($local_variable_context)) {
                if ($variable_table_parent_node_id !== null) {
                    $local_variable_context = $this->emitChildIfStreaming(
                        $name,
                        $local_variable_context,
                        $variable_table_parent_node_id,
                    );
                    $this->flushPoolsIfStreaming();
                }
                $variable_table_context->add($name, $local_variable_context);
                $has_local_variables = true;
            }
        }
        if ($has_local_variables) {
            $call_frame_context->add('local_variables', $variable_table_context);
        }

        if ($execute_data->hasSymbolTable() and !is_null($execute_data->symbol_table)) {
            $symbol_table_context = $this->collectZendArrayPointer(
                $execute_data->symbol_table,
                $map_ptr_base,
                $memory_locations,
                $dereferencer,
                $zend_type_reader,
                $context_pools,
                $memory_limit_error_details,
            );
            if ($symbol_table_context !== null) {
                $call_frame_context->add('symbol_table', $symbol_table_context);
            }
        }
        if ($execute_data->hasExtraNamedParams() and !is_null($execute_data->extra_named_params)) {
            $extra_named_params_context = $this->collectZendArrayPointer(
                $execute_data->extra_named_params,
                $map_ptr_base,
                $memory_locations,
                $dereferencer,
                $zend_type_reader,
                $context_pools,
                $memory_limit_error_details,
            );
            if ($extra_named_params_context !== null) {
                $call_frame_context->add('extra_named_params', $extra_named_params_context);
            }
        }
        return $call_frame_context;
    }

    public function collectGlobalVariables(
        ZendArray $array,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): GlobalVariablesContext {
        return GlobalVariablesContext::fromArrayContext(
            $this->collectZendArray(
                $array,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            )
        );
    }

    public function collectZendArray(
        ZendArray $array,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ArrayHeaderContext {
        $array_header_location = ZendArrayMemoryLocation::fromZendArray($array);
        $memory_locations->add($array_header_location);
        $array_header_context = $context_pools
            ->array_context_pool
            ->getContextForLocation($array_header_location)
        ;
        if (is_null($array->arData)) {
            return $array_header_context;
        }

        $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($array);
        $array_table_overhead_location = ZendArrayTableOverheadMemoryLocation::fromZendArrayAndUsedLocation(
            $array,
            $array_table_location
        );

        $memory_locations->add($array_table_location);
        $memory_locations->add($array_table_overhead_location);

        $array_context = new ArrayElementsContext($array_table_location);
        $overhead_context = new ArrayPossibleOverheadContext($array_table_overhead_location);
        $array_elements_parent_node_id = $this->registerParentIfStreaming($array_context);

        foreach ($array->getItemIteratorWithZendStringKeyIfAssoc($dereferencer) as $key => $zval) {
            $element_context = new ArrayElementContext();
            if ($key instanceof Pointer) {
                $key_context = $this->collectZendStringPointer(
                    $key,
                    $memory_locations,
                    $dereferencer,
                    $context_pools,
                );
                $zend_string = $dereferencer->deref($key);
                $key_string = $zend_string->toString($dereferencer);
                $element_context->add('key', $key_context);
            } else {
                $key_string = (string)$key;
            }
            $value_context = $this->collectZval(
                $zval,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if (!is_null($value_context)) {
                $element_context->add('value', $value_context);
            }
            if ($array_elements_parent_node_id !== null) {
                $element_context = $this->emitChildIfStreaming(
                    $key_string,
                    $element_context,
                    $array_elements_parent_node_id,
                );
                $this->flushPoolsIfStreaming();
            }
            $array_context->add($key_string, $element_context);
        }
        $array_header_context->add('possible_unused_area', $overhead_context);
        $array_header_context->add('array_elements', $array_context);
        return $array_header_context;
    }

    public function collectZendObject(
        ZendObject $object,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ObjectContext {
        $object_location = ZendObjectMemoryLocation::fromZendObject(
            $object,
            $dereferencer,
            $zend_type_reader,
        );
        $object_handlers_memory_location = ZendObjectHandlersMemoryLocation::fromZendObject(
            $object,
            $zend_type_reader,
        );
        $memory_locations->add($object_location);
        $zend_object_address = $object->getPointer()->address;
        if ($object_location->address !== $zend_object_address) {
            $memory_locations->memory_locations[$zend_object_address] = $object_location;
        }
        $memory_locations->add($object_handlers_memory_location);

        $object_context = $context_pools
            ->object_context_pool->getContextForLocation(
                $object_location,
            )
        ;
        $object_handlers_context = $context_pools
            ->object_context_pool
            ->getHandlersContextForLocation(
                $object_handlers_memory_location,
            )
        ;
        $object_context->add('object_handlers', $object_handlers_context);

        // In streaming mode, enable defer for all nested object references
        // within this object. This prevents deep recursion through the
        // object graph — referenced objects will be collected later from
        // their own objects_store bucket.
        $saved_defer = $this->defer_unseen_objects;
        if ($this->streaming_sink !== null) {
            $this->defer_unseen_objects = true;
        }

        $properties_exists = false;
        $object_properties_context = new ObjectPropertiesContext();
        $properties_parent_node_id = $this->registerParentIfStreaming($object_properties_context);
        $saved_parent_node_id = $this->current_streaming_parent_node_id;
        if ($properties_parent_node_id !== null) {
            $this->current_streaming_parent_node_id = $properties_parent_node_id;
        }
        $properties_iterator = $object->getPropertiesIterator(
            $dereferencer,
            $zend_type_reader,
        );
        foreach ($properties_iterator as $name => $property) {
            assert(is_string($name));
            $this->current_streaming_link_name = $name;
            $property_context = $this->collectZval(
                $property,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if (!is_null($property_context)) {
                if ($properties_parent_node_id !== null) {
                    $property_context = $this->emitChildIfStreaming(
                        $name,
                        $property_context,
                        $properties_parent_node_id,
                    );
                    $this->flushPoolsIfStreaming();
                }
                $object_properties_context->add($name, $property_context);
                $properties_exists = true;
            }
        }
        $this->current_streaming_parent_node_id = $saved_parent_node_id;
        if ($properties_exists) {
            $object_context->add('object_properties', $object_properties_context);
        }

        // PDO internal data collection follows extension-internal pointers
        // (pdo_dbh_t, pdo_stmt_t), not object references, so it is safe
        // even during deferred/shallow collection.
        if ($object_location->class_name === \PDO::class) {
            try {
                $this->collectPdoDbhObject(
                    $object,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                    $object_context,
                );
            } catch (\Throwable) {
            }
        }

        if ($object_location->class_name === \PDOStatement::class) {
            try {
                $this->collectPdoStmt(
                    $object,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                    $object_context,
                );
            } catch (\Throwable) {
            }
        }

        // When defer is active (shallow collection for objects_store),
        // skip dynamic properties, closures, generators, and fibers.
        // These can trigger deep recursive expansion. They will be
        // collected when the object is reached from another phase, or
        // via deferred edge resolution.
        if ($this->defer_unseen_objects) {
            $this->defer_unseen_objects = $saved_defer;
            return $object_context;
        }

        if (
            !is_null($object->properties)
            and !is_null($object->ce)
            and !$object->isEnum($dereferencer)
        ) {
            $dynamic_properties_context = $this->collectZendArray(
                $dereferencer->deref($object->properties),
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            $object_context->add('dynamic_properties', $dynamic_properties_context);
        }

        assert(!is_null($object->ce));
        $class_entry = $dereferencer->deref($object->ce);
        if (
            $class_entry->getClassName($dereferencer) === 'Closure'
            and !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V71)
        ) {
            $closure_context = $this->collectClosure(
                $dereferencer->deref(
                    ZendClosure::getPointerFromZendObjectPointer(
                        $object->getPointer(),
                        $zend_type_reader,
                    ),
                ),
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            $object_context->add('closure', $closure_context);
        }

        if ($class_entry->getClassName($dereferencer) === 'Generator') {
            try {
                $generator_context = $this->collectGenerator(
                    $dereferencer->deref(
                        ZendGenerator::getPointerFromZendObjectPointer(
                            $object->getPointer(),
                            $zend_type_reader,
                        ),
                    ),
                    $map_ptr_base,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                    $memory_limit_error_details,
                );
                $object_context->add('generator', $generator_context);
            } catch (\Throwable) {
            }
        }

        if (
            $class_entry->getClassName($dereferencer) === 'Fiber'
            and !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V81)
        ) {
            try {
                $fiber_context = $this->collectFiber(
                    $dereferencer->deref(
                        ZendFiber::getPointerFromZendObjectPointer(
                            $object->getPointer(),
                            $zend_type_reader,
                        ),
                    ),
                    $map_ptr_base,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                    $memory_limit_error_details,
                );
                $object_context->add('fiber', $fiber_context);
            } catch (\Throwable) {
            }
        }

        if (
            $class_entry->getClassName($dereferencer) === 'WeakReference'
            and !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V74)
        ) {
            try {
                $weak_reference_context = $this->collectWeakReference(
                    $dereferencer->deref(
                        ZendWeakReference::getPointerFromZendObjectPointer(
                            $object->getPointer(),
                            $zend_type_reader,
                        ),
                    ),
                    $map_ptr_base,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                    $memory_limit_error_details,
                );
                $object_context->add('weak_reference', $weak_reference_context);
            } catch (\Throwable) {
            }
        }

        if (
            $class_entry->getClassName($dereferencer) === 'WeakMap'
            and !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V80)
        ) {
            try {
                $weak_map_context = $this->collectWeakMap(
                    $dereferencer->deref(
                        ZendWeakMap::getPointerFromZendObjectPointer(
                            $object->getPointer(),
                            $zend_type_reader,
                        ),
                    ),
                    $map_ptr_base,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                    $memory_limit_error_details,
                );
                $object_context->add('weak_map', $weak_map_context);
            } catch (\Throwable) {
            }
        }

        $this->defer_unseen_objects = $saved_defer;
        return $object_context;
    }

    public function collectClosure(
        ZendClosure $zend_closure,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ClosureContext {
        $closure_context = new ClosureContext();
        $closure_context->add(
            'func',
            $this->collectZendFunctionPointer(
                $zend_closure->func->getPointer(),
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            )
        );
        $zval_context = $this->collectZval(
            $zend_closure->this_ptr,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
        if (!is_null($zval_context)) {
            $closure_context->add(
                'this_ptr',
                $zval_context,
            );
        }
        return $closure_context;
    }

    public function collectGenerator(
        ZendGenerator $zend_generator,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): GeneratorContext {
        $generator_context = new GeneratorContext();

        try {
            if (
                $zend_generator->execute_data !== null
                and !$this->isExecuteDataInsideGeneratorStruct(
                    $zend_generator,
                    $zend_type_reader,
                )
            ) {
                $execute_data = $dereferencer->deref($zend_generator->execute_data);
                $call_frames_context = new CallFramesContext();
                foreach ($execute_data->iterateStackChain($dereferencer) as $key => $frame) {
                    $call_frame_context = $this->collectCallFrame(
                        $frame,
                        $map_ptr_base,
                        $dereferencer,
                        $zend_type_reader,
                        $memory_locations,
                        $context_pools,
                        $memory_limit_error_details,
                    );
                    $call_frames_context->add((string)$key, $call_frame_context);
                }
                $generator_context->add('call_frames', $call_frames_context);
            }
        } catch (\Throwable) {
        }

        try {
            $value_context = $this->collectZval(
                $zend_generator->value,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if (!is_null($value_context)) {
                $generator_context->add('value', $value_context);
            }
        } catch (\Throwable) {
        }

        try {
            $key_context = $this->collectZval(
                $zend_generator->key,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if (!is_null($key_context)) {
                $generator_context->add('key', $key_context);
            }
        } catch (\Throwable) {
        }

        try {
            $retval_context = $this->collectZval(
                $zend_generator->retval,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if (!is_null($retval_context)) {
                $generator_context->add('retval', $retval_context);
            }
        } catch (\Throwable) {
        }

        return $generator_context;
    }

    /**
     * Check if generator->execute_data points to the inline execute_fake field
     * inside the zend_generator struct. This happens during yield-from delegation.
     * Collecting call frames from execute_fake would double-count the generator's
     * own ZendMM allocation, inflating analyzed_percentage above 100%.
     */
    private function isExecuteDataInsideGeneratorStruct(
        ZendGenerator $zend_generator,
        ZendTypeReader $zend_type_reader,
    ): bool {
        assert($zend_generator->execute_data !== null);
        $execute_data_address = $zend_generator->execute_data->address;
        $generator_address = $zend_generator->getPointer()->address;
        $generator_size = $zend_type_reader->sizeOf('zend_generator');
        return $execute_data_address >= $generator_address
            && $execute_data_address < $generator_address + $generator_size;
    }

    public function collectFiber(
        ZendFiber $zend_fiber,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): FiberContext {
        $fiber_context = new FiberContext();

        try {
            if ($zend_fiber->execute_data !== null) {
                $execute_data = $dereferencer->deref($zend_fiber->execute_data);
                $call_frames_context = new CallFramesContext();
                foreach ($execute_data->iterateStackChain($dereferencer) as $key => $frame) {
                    $call_frame_context = $this->collectCallFrame(
                        $frame,
                        $map_ptr_base,
                        $dereferencer,
                        $zend_type_reader,
                        $memory_locations,
                        $context_pools,
                        $memory_limit_error_details,
                    );
                    $call_frames_context->add((string)$key, $call_frame_context);
                }
                $fiber_context->add('call_frames', $call_frames_context);
            }
        } catch (\Throwable) {
        }

        try {
            if (
                !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V82)
                and $zend_fiber->vm_stack !== null
                and $this->fiber_vm_stack_memory_locations !== null
            ) {
                $vm_stack_current = $dereferencer->deref($zend_fiber->vm_stack);
                foreach ($vm_stack_current->iterateStackChain($dereferencer) as $vm_stack) {
                    $this->fiber_vm_stack_memory_locations->add(
                        VmStackMemoryLocation::fromZendVmStack($vm_stack),
                    );
                }
            } elseif (
                !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V81)
                and $zend_fiber->execute_data !== null
                and $this->fiber_vm_stack_memory_locations !== null
                and $this->chunk_memory_locations !== null
            ) {
                // fiber->vm_stack is only set when the fiber's callback returns
                // (end of zend_fiber_execute). For suspended fibers, EG(vm_stack)
                // is saved to a local zend_fiber_vm_state on the C stack via
                // zend_fiber_capture_vm_state(), so we must scan the C stack to
                // find it. This applies to all PHP versions with Fiber support.
                $this->scanFiberCStackForVmStack(
                    $zend_fiber,
                    $dereferencer,
                    $zend_type_reader,
                );
            }
        } catch (\Throwable) {
        }

        return $fiber_context;
    }

    /**
     * When a fiber suspends, zend_fiber_switch_context() saves EG(vm_stack) to
     * a local zend_fiber_vm_state variable on the C stack via
     * zend_fiber_capture_vm_state(). The fiber->vm_stack field is only set at
     * the end of zend_fiber_execute() when the fiber's callback returns, so it
     * is always NULL for suspended fibers regardless of PHP version.
     *
     * This method brute-force scans the fiber's C stack memory to locate the
     * saved vm_stack pointer. For each pointer-sized value on the C stack:
     *   1. Check if it points into a ZendMM chunk (quick rejection of most values)
     *   2. Read the candidate's first two fields (top, end) as raw integers
     *   3. Validate: top < end, top > candidate address, top is in a chunk
     *   4. Verify the fiber's execute_data falls within the [top, end) range
     *
     * The execute_data check is the strongest validation — it's very unlikely
     * for a false positive to pass all four checks.
     */
    private function scanFiberCStackForVmStack(
        ZendFiber $zend_fiber,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): void {
        assert($zend_fiber->execute_data !== null);
        assert($this->fiber_vm_stack_memory_locations !== null);
        assert($this->chunk_memory_locations !== null);

        $context_stack = $zend_fiber->context_stack;
        if ($context_stack === null) {
            return;
        }
        $fiber_stack = $dereferencer->deref($context_stack);
        if ($fiber_stack->pointer === 0 || $fiber_stack->size === 0) {
            return;
        }

        // In php-src, zend_fiber_stack_allocate() sets:
        //   stack->pointer = mmap_base + guard_pages (already past the guard page)
        //   stack->size    = usable stack size (excludes guard pages)
        // So pointer/size describe the usable region directly — no adjustment needed.
        //
        // The C stack grows downward (high→low on x86-64), so the saved
        // zend_fiber_vm_state local variable is near the top (high address end).
        // Scan from the top of the stack to maximise the chance of finding it.
        $usable_size = $fiber_stack->size;
        if ($usable_size <= 0) {
            return;
        }

        $scan_size = min($usable_size, 256 * 1024);
        $scan_start = $fiber_stack->pointer + $usable_size - $scan_size;

        $c_stack_pointer = new Pointer(
            PointerArray::class,
            $scan_start,
            $scan_size,
        );
        $c_stack = $dereferencer->deref($c_stack_pointer);
        $execute_data_address = $zend_fiber->execute_data->address;

        $vm_stack_size = $zend_type_reader->sizeOf('struct _zend_vm_stack');

        foreach ($c_stack->getReverseIteratorAsInt() as $value) {
            if ($value === 0) {
                continue;
            }

            // Step 1: is this value a pointer into a ZendMM chunk?
            $candidate_location = new \Reli\Lib\Process\MemoryLocation($value, 1);
            if (!$this->chunk_memory_locations->contains($candidate_location)) {
                continue;
            }

            // Step 2: read the candidate's top/end fields as raw integers.
            // We avoid deref-ing as ZendVmStack here because FFI can SEGV when
            // casting garbage data that happens to contain a non-null void* to
            // Pointer (FFI dereferences void* internally on cast).
            try {
                $raw_top_pointer = new Pointer(RawInt64::class, $value, 8);
                $raw_top = $dereferencer->deref($raw_top_pointer)->value;
                $raw_end_pointer = new Pointer(RawInt64::class, $value + 8, 8);
                $raw_end = $dereferencer->deref($raw_end_pointer)->value;
            } catch (\Throwable) {
                continue;
            }

            // Step 3: structural sanity checks on the vm_stack layout
            if ($raw_top === 0 || $raw_end === 0) {
                continue;
            }
            if ($raw_top >= $raw_end) {
                continue;
            }
            // top must be above the struct header (top points into the data area)
            if ($raw_top <= $value) {
                continue;
            }
            $top_location = new \Reli\Lib\Process\MemoryLocation($raw_top, 1);
            if (!$this->chunk_memory_locations->contains($top_location)) {
                continue;
            }

            // Step 4: the fiber's execute_data must reside within [top, end)
            if (
                $execute_data_address < $raw_top
                || $execute_data_address >= $raw_end
            ) {
                continue;
            }

            // All checks passed — collect this vm_stack and its prev chain
            try {
                /** @var Pointer<ZendVmStack> $vm_stack_pointer */
                $vm_stack_pointer = new Pointer(
                    ZendVmStack::class,
                    $value,
                    $vm_stack_size,
                );
                /** @var ZendVmStack $vm_stack_candidate */
                $vm_stack_candidate = $dereferencer->deref($vm_stack_pointer);
                foreach ($vm_stack_candidate->iterateStackChain($dereferencer) as $vm_stack) {
                    $this->fiber_vm_stack_memory_locations->add(
                        VmStackMemoryLocation::fromZendVmStack($vm_stack),
                    );
                }
                return;
            } catch (\Throwable) {
                continue;
            }
        }
    }

    public function collectWeakReference(
        ZendWeakReference $zend_weak_reference,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): WeakReferenceContext {
        $weak_reference_context = new WeakReferenceContext();

        if ($zend_weak_reference->referent !== null) {
            try {
                $referent_context = $this->collectZendObjectPointer(
                    $zend_weak_reference->referent,
                    $map_ptr_base,
                    $memory_locations,
                    $dereferencer,
                    $zend_type_reader,
                    $context_pools,
                    $memory_limit_error_details,
                );
                if ($referent_context !== null) {
                    $weak_reference_context->add('referent', $referent_context);
                }
            } catch (\Throwable) {
            }
        }

        return $weak_reference_context;
    }

    public function collectWeakMap(
        ZendWeakMap $zend_weak_map,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): WeakMapContext {
        $weak_map_context = new WeakMapContext();

        try {
            $array_context = $this->collectZendArray(
                $zend_weak_map->ht,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            $weak_map_context->add('entries', $array_context);
        } catch (\Throwable) {
        }

        return $weak_map_context;
    }

    public function collectFunctionTable(
        ZendArray $array,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): DefinedFunctionsContext {
        $array_header_location = ZendArrayMemoryLocation::fromZendArray($array);
        $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($array);
        $array_table_overhead_location = ZendArrayTableOverheadMemoryLocation::fromZendArrayAndUsedLocation(
            $array,
            $array_table_location
        );

        $memory_locations->add($array_header_location);
        $memory_locations->add($array_table_location);
        $memory_locations->add($array_table_overhead_location);

        $defined_functions_context = new DefinedFunctionsContext(
            $array_header_location,
            $array_table_location,
        );
        $parent_node_id = $this->registerParentIfStreaming($defined_functions_context);

        foreach ($array->getItemIterator($dereferencer) as $function_name => $zval) {
            assert(is_string($function_name));
            assert(!is_null($zval->value->func));
            $function_context = $this->collectZendFunctionPointer(
                $zval->value->func,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if ($parent_node_id !== null) {
                $function_context = $this->emitChildIfStreaming(
                    $function_name,
                    $function_context,
                    $parent_node_id,
                );
                $this->flushPoolsIfStreaming();
            }
            $defined_functions_context->add($function_name, $function_context);
        }
        return $defined_functions_context;
    }

    /** @param Pointer<ZendFunction> $pointer */
    public function collectZendFunctionPointer(
        Pointer $pointer,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): FunctionDefinitionContext|SentinelContext {
        if ($memory_locations->has($pointer->address)) {
            $sentinel = $context_pools->getSentinel($pointer->address);
            if ($sentinel !== null) {
                return $sentinel;
            }
            $cached = $context_pools->user_function_definition_context_pool->getContextByAddress($pointer->address);
            if ($cached !== null) {
                return $cached;
            }
        }
        $func = $dereferencer->deref($pointer);
        return $this->collectZendFunction(
            $func,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
    }

    public function collectZendFunction(
        ZendFunction $func,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): FunctionDefinitionContext {
        if ($func->isUserFunction()) {
            $function_definition_context = $this->collectUserFunctionDefinition(
                $func,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
        } else {
            $function_definition_context = new InternalFunctionDefinitionContext();
        }
        if (!is_null($func->function_name)) {
            $function_name_context = $this->collectZendStringPointer(
                $func->function_name,
                $memory_locations,
                $dereferencer,
                $context_pools,
            );
            $function_definition_context->add('name', $function_name_context);
        }
        return $function_definition_context;
    }

    public function collectUserFunctionDefinition(
        ZendFunction $func,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): UserFunctionDefinitionContext {
        $function_name = $func->getFullyQualifiedFunctionName(
            $dereferencer,
            $zend_type_reader,
        );
        $op_array_header_memory_location = ZendOpArrayHeaderMemoryLocation::fromZendFunction(
            $func,
            $zend_type_reader,
            $dereferencer,
        );
        $op_array_body_memory_location = ZendOpArrayBodyMemoryLocation::fromZendFunction(
            $func,
            $zend_type_reader,
            $function_name
        );
        $memory_locations->add($op_array_header_memory_location);
        $memory_locations->add($op_array_body_memory_location);
        $function_definition_context = $context_pools
            ->user_function_definition_context_pool
            ->getContextForLocation($op_array_header_memory_location)
        ;
        $op_array_context = new OpArrayContext(
            $op_array_header_memory_location,
            $op_array_body_memory_location,
        );
        $function_definition_context->add('op_array', $op_array_context);

        if ($func->op_array->cache_size > 0) {
            $runtime_cache_memory_location = RuntimeCacheMemoryLocation::fromZendOpArray(
                $func->op_array,
                $dereferencer,
                $zend_type_reader,
                $map_ptr_base,
            );
            if ($runtime_cache_memory_location->address !== 0) {
                $memory_locations->add($runtime_cache_memory_location);
                $run_time_cache_context = new RuntimeCacheContext($runtime_cache_memory_location);
                $op_array_context->add('run_time_cache', $run_time_cache_context);
            }
        }

        if (!is_null($func->op_array->arg_info)) {
            $arginfos_memory_location = ZendArgInfosMemoryLocation::fromZendOpArray(
                $func->op_array,
                $zend_type_reader,
            );
            $memory_locations->add($arginfos_memory_location);
            $arginfos_context = new ArgInfosContext($arginfos_memory_location);
            $op_array_context->add('arg_infos', $arginfos_context);
            foreach ($func->op_array->iterateArgInfo($dereferencer, $zend_type_reader) as $arg_info) {
                if (!is_null($arg_info->name)) {
                    $arg_info_context = new ArgInfoContext();
                    $arg_info_name_context = $this->collectZendStringPointer(
                        $arg_info->name,
                        $memory_locations,
                        $dereferencer,
                        $context_pools,
                    );
                    $arg_info_name = $dereferencer
                        ->deref($arg_info->name)
                        ->toString($dereferencer)
                    ;
                    $arg_info_context->add('name', $arg_info_name_context);
                    $arginfos_context->add($arg_info_name, $arg_info_context);
                }
            }
        }

        if (!is_null($func->op_array->doc_comment)) {
            $doc_comment_context = $this->collectZendStringPointer(
                $func->op_array->doc_comment,
                $memory_locations,
                $dereferencer,
                $context_pools,
            );
            $op_array_context->add('doc_comment', $doc_comment_context);
        }

        if (!is_null($func->op_array->filename)) {
            $file_name_context = $this->collectZendStringPointer(
                $func->op_array->filename,
                $memory_locations,
                $dereferencer,
                $context_pools,
            );
            $op_array_context->add('filename', $file_name_context);
        }

        if (!is_null($func->op_array->static_variables)) {
            $static_variables_context = $this->collectZendArray(
                $dereferencer->deref($func->op_array->static_variables),
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            $op_array_context->add('static_variables', $static_variables_context);
        }

        if (!is_null($func->op_array->vars)) {
            $local_variable_name_table_location = LocalVariableNameTableMemoryLocation::fromZendOpArray(
                $func->op_array
            );
            $memory_locations->add($local_variable_name_table_location);
            $variable_name_table_context = new LocalVariableNameTableContext(
                $local_variable_name_table_location
            );
            $op_array_context->add('variable_name_table', $variable_name_table_context);

            $variable_names_iterator = $func->op_array->getVariableNamesAsIteratorOfPointersToZendStrings(
                $dereferencer,
                $zend_type_reader,
            );
            foreach ($variable_names_iterator as $key => $variable_name) {
                $variable_name_context = $this->collectZendStringPointer(
                    $variable_name,
                    $memory_locations,
                    $dereferencer,
                    $context_pools,
                );
                $variable_name_table_context->add((string)$key, $variable_name_context);
            }
        }

        if ($func->op_array->num_dynamic_func_defs > 0) {
            $dynamic_func_defs_table_memory_location = DynamicFuncDefsTableMemoryLocation::fromZendOpArray(
                $func->op_array,
            );
            $memory_locations->add($dynamic_func_defs_table_memory_location);
            $dynamic_func_defs_context = new DynamicFuncDefsContext(
                $dynamic_func_defs_table_memory_location
            );
            $dynamic_func_defs_iterator = $func->op_array->iterateDynamicFunctionDefinitions(
                $dereferencer,
                $zend_type_reader,
            );
            foreach ($dynamic_func_defs_iterator as $key => $dynamic_func_def) {
                $dynamic_function_context = $this->collectZendFunctionPointer(
                    $dynamic_func_def,
                    $map_ptr_base,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                    $memory_limit_error_details,
                );
                $dynamic_func_defs_context->add((string)$key, $dynamic_function_context);
            }
            $op_array_context->add('dynamic_function_definitions', $dynamic_func_defs_context);
        }

        if (!is_null($memory_limit_error_details)) {
            if (
                $function_definition_context->isThisContext(
                    $memory_limit_error_details->file,
                    $memory_limit_error_details->line,
                )
            ) {
                if (
                    is_null($this->memory_limit_error_function_context)
                    or $function_definition_context->isClosureOf($this->memory_limit_error_function_context)
                ) {
                    $this->memory_limit_error_function_context = $function_definition_context;
                }
            }
        }
        return $function_definition_context;
    }

    public function collectClassConstantsTable(
        ZendArray $array,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ClassConstantsContext {
        $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($array);
        $array_table_overhead_location = ZendArrayTableOverheadMemoryLocation::fromZendArrayAndUsedLocation(
            $array,
            $array_table_location
        );
        $memory_locations->add($array_table_location);
        $memory_locations->add($array_table_overhead_location);
        $class_constants_context = new ClassConstantsContext($array_table_location);

        $array_iterator = $array->getItemIteratorWithZendStringKeyIfAssoc($dereferencer);
        foreach ($array_iterator as $constant_name => $zval_or_ptr) {
            assert($constant_name instanceof Pointer);
            $constant_name_context = $this->collectZendStringPointer(
                $constant_name,
                $memory_locations,
                $dereferencer,
                $context_pools,
            );
            $zend_string = $dereferencer->deref($constant_name);
            $constant_name_string = $zend_string->toString($dereferencer);
            $constant_context = new ClassConstantContext();
            $class_constants_context->add($constant_name_string, $constant_context);
            $constant_context->add('name', $constant_name_context);

            if ($zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V71)) {
                $zval = $zval_or_ptr;
            } else {
                $class_constant_ptr = $zval_or_ptr->value->getAsPointer(
                    ZendClassConstant::class,
                    $zend_type_reader->sizeOf(ZendClassConstant::getCTypeName()),
                );
                $class_constant = $dereferencer->deref(
                    $class_constant_ptr
                );
                $memory_location = ZendClassConstantMemoryLocation::fromZendClassConstant(
                    $class_constant,
                );
                $memory_locations->add($memory_location);
                $zval = $class_constant->value;
                $info_context = new ClassConstantInfoContext($memory_location);
                $constant_context->add('info', $info_context);
            }
            $value_context = $this->collectZval(
                $zval,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if (!is_null($value_context)) {
                $constant_context->add('value', $value_context);
            }
        }

        return $class_constants_context;
    }

    public function collectClassTable(
        ZendArray $array,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): DefinedClassesContext {
        $defined_classes_context = new DefinedClassesContext();
        $parent_node_id = $this->registerParentIfStreaming($defined_classes_context);
        foreach ($array->getItemIterator($dereferencer) as $class_name => $zval) {
            assert(!is_null($zval->value->ce));
            $class_definition_context = $this->collectClassDefinitionPointer(
                $zval->value->ce,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if (!is_null($class_definition_context)) {
                if ($parent_node_id !== null) {
                    $class_definition_context = $this->emitChildIfStreaming(
                        (string)$class_name,
                        $class_definition_context,
                        $parent_node_id,
                    );
                    $this->flushPoolsIfStreaming();
                }
                $defined_classes_context->add((string)$class_name, $class_definition_context);
            }
        }
        return $defined_classes_context;
    }

    /** @param Pointer<ZendClassEntry> $pointer */
    private function collectClassDefinitionPointer(
        Pointer $pointer,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ?ClassDefinitionContext {
        if ($memory_locations->has($pointer->address)) {
            return null;
        }
        $class_entry = $dereferencer->deref($pointer);
        return $this->collectClassDefinition(
            $class_entry,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
    }

    private function collectClassDefinition(
        ZendClassEntry $class_entry,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ClassDefinitionContext {
        $class_definition_context = new ClassDefinitionContext($class_entry->isInternal());
        $memory_location = ZendClassEntryMemoryLocation::fromZendClassEntry($class_entry);
        $memory_locations->add($memory_location);
        $class_entry_context = new ClassEntryContext($memory_location);
        $class_definition_context->add('class_entry', $class_entry_context);

        $class_name_context = $this->collectZendStringPointer(
            $class_entry->name,
            $memory_locations,
            $dereferencer,
            $context_pools,
        );
        $class_definition_context->add('name', $class_name_context);

        if (!$class_entry->isInternal()) {
            if (!is_null($class_entry->info->user->filename)) {
                $file_name_context = $this->collectZendStringPointer(
                    $class_entry->info->user->filename,
                    $memory_locations,
                    $dereferencer,
                    $context_pools,
                );
                $class_definition_context->add('filename', $file_name_context);
            }

            if (!is_null($doc_comment = $class_entry->getDocCommentPointer($zend_type_reader))) {
                $doc_comment_context = $this->collectZendStringPointer(
                    $doc_comment,
                    $memory_locations,
                    $dereferencer,
                    $context_pools,
                );
                $class_definition_context->add('doc_comment', $doc_comment_context);
            }
        }

        if (
            $class_entry->default_static_members_count > 0
            and !is_null($class_entry->static_members_table)
        ) {
            $static_members_table_memory_location = StaticMembersTableMemoryLocation::fromZendClassEntry(
                $class_entry,
                $zend_type_reader,
                $dereferencer,
                $map_ptr_base,
            );
            $memory_locations->add($static_members_table_memory_location);
            $static_properties_context = new ClassStaticPropertiesContext(
                $static_members_table_memory_location
            );
            $static_property_iterator = $class_entry->getStaticPropertyIterator(
                $dereferencer,
                $zend_type_reader,
                $map_ptr_base,
            );
            foreach ($static_property_iterator as $name => $value) {
                $static_property_context = $this->collectZval(
                    $value,
                    $map_ptr_base,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                    $memory_limit_error_details,
                );
                if (!is_null($static_property_context)) {
                    $static_properties_context->add($name, $static_property_context);
                }
            }
            $class_definition_context->add('static_properties', $static_properties_context);
        }

        $properties_info_context = $this->collectPropertiesInfo(
            $class_entry,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
        );
        $class_definition_context->add('property_info', $properties_info_context);

        if (!is_null($class_entry->default_properties_table)) {
            $default_properties_table_memory_location = DefaultPropertiesTableMemoryLocation::fromZendClassEntry(
                $class_entry,
            );
            $memory_locations->add($default_properties_table_memory_location);
            $default_properties_context = new DefaultPropertiesTableContext(
                $default_properties_table_memory_location
            );
            $class_definition_context->add('default_properties', $default_properties_context);
        }

        if (!is_null($class_entry->default_static_members_table)) {
            $default_static_members_memory_location = DefaultStaticMembersTableMemoryLocation::fromZendClassEntry(
                $class_entry,
            );
            $memory_locations->add($default_static_members_memory_location);
            $default_static_properties_context = new DefaultStaticPropertiesContext(
                $default_static_members_memory_location
            );
            $class_definition_context->add('default_static_properties', $default_static_properties_context);
        }

        $methods_context = $this->collectFunctionTable(
            $dereferencer->deref($class_entry->function_table->getPointer()),
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
        $class_definition_context->add('methods', $methods_context);

        $class_constants_context = $this->collectClassConstantsTable(
            $dereferencer->deref($class_entry->constants_table->getPointer()),
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
        $class_definition_context->add('constants', $class_constants_context);

        return $class_definition_context;
    }

    private function collectPropertiesInfo(
        ZendClassEntry $class_entry,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools
    ): PropertiesInfoContext {
        $memory_location = ZendArrayTableMemoryLocation::fromZendArray($class_entry->properties_info);
        $memory_locations->add($memory_location);

        $properties_info_context = new PropertiesInfoContext($memory_location);

        foreach ($class_entry->iteratePropertyInfo($dereferencer, $zend_type_reader) as $key => $property_info) {
            $property_info_memory_location = ZendPropertyInfoMemoryLocation::fromZendPropertyInfo(
                $property_info,
            );
            $memory_locations->add($property_info_memory_location);
            $property_info_context = new PropertyInfoContext($property_info_memory_location);
            if (!is_null($property_info->name)) {
                $property_info_name_context = $this->collectZendStringPointer(
                    $property_info->name,
                    $memory_locations,
                    $dereferencer,
                    $context_pools,
                );
                $property_info_context->add('name', $property_info_name_context);
            }
            if (!is_null($property_info->doc_comment)) {
                $property_info_doc_comment_context = $this->collectZendStringPointer(
                    $property_info->doc_comment,
                    $memory_locations,
                    $dereferencer,
                    $context_pools,
                );
                $property_info_context->add('doc_comment', $property_info_doc_comment_context);
            }
            $properties_info_context->add($key, $property_info_context);
        }
        return $properties_info_context;
    }

    private function collectGlobalConstants(
        ZendArray $array,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): GlobalConstantsContext {
        $memory_location = ZendArrayTableMemoryLocation::fromZendArray($array);
        $memory_locations->add($memory_location);

        $global_constants_context = new GlobalConstantsContext($memory_location);
        foreach ($array->getItemIterator($dereferencer) as $constant_name => $zval) {
            assert(is_string($constant_name));
            $zend_constant = $dereferencer->deref(
                $zval->value->getAsPointer(
                    ZendConstant::class,
                    $zend_type_reader->sizeOf(ZendConstant::getCTypeName()),
                )
            );
            $zend_constant_memory_location = ZendConstantMemoryLocation::fromZendConstant(
                $zend_constant
            );
            $constant_context = new GlobalConstantContext($zend_constant_memory_location);
            $global_constants_context->add($constant_name, $constant_context);

            if (!is_null($zend_constant->name)) {
                $constant_name_context = $this->collectZendStringPointer(
                    $zend_constant->name,
                    $memory_locations,
                    $dereferencer,
                    $context_pools,
                );
                $constant_context->add('name', $constant_name_context);
            }

            $value_context = $this->collectZval(
                $zend_constant->value,
                $map_ptr_base,
                $dereferencer,
                $zend_type_reader,
                $memory_locations,
                $context_pools,
                $memory_limit_error_details,
            );
            if (!is_null($value_context)) {
                $constant_context->add('value', $value_context);
            }
        }
        return $global_constants_context;
    }

    private function collectIncludedFiles(
        ZendArray $included_files,
        Dereferencer $dereferencer,
        MemoryLocations $memory_locations,
        ContextPools $context_pools
    ): IncludedFilesContext {
        $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($included_files);
        $included_files_context = new IncludedFilesContext($array_table_location);

        $memory_locations->add($array_table_location);

        $iterator = $included_files->getItemIteratorWithZendStringKeyIfAssoc($dereferencer);
        foreach ($iterator as $filename => $_) {
            assert($filename instanceof Pointer);
            $raw_string = $dereferencer->deref($filename)->toString($dereferencer);
            $included_file_context = $this->collectZendStringPointer(
                $filename,
                $memory_locations,
                $dereferencer,
                $context_pools,
            );
            $included_files_context->add($raw_string, $included_file_context);
        }
        return $included_files_context;
    }

    private function collectInternedStrings(
        ZendArray $interned_string,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools
    ): ArrayHeaderContext {
        return $this->collectZendArray(
            $interned_string,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            null
        );
    }

    private function collectObjectsStore(
        ZendObjectsStore $objects_store,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ObjectsStoreContext {
        $objects_store_memory_location = ObjectsStoreMemoryLocation::fromZendObjectsStore(
            $objects_store,
        );
        $objects_store_context = new ObjectsStoreContext($objects_store_memory_location);
        $memory_locations->add($objects_store_memory_location);
        $parent_node_id = $this->registerParentIfStreaming($objects_store_context);

        assert($objects_store->object_buckets instanceof Pointer);
        $buckets = $dereferencer->deref($objects_store->object_buckets);
        $bucket_iterator = $buckets->getIteratorOfPointersTo(
            ZendObject::class,
            $zend_type_reader,
        );

        foreach ($bucket_iterator as $key => $bucket) {
            if ($key === 0) {
                continue;
            }
            if ($bucket->address & 1) {
                continue;
            }
            if ($bucket->address === 0) {
                continue;
            }
            if ($key >= $objects_store->top) {
                break;
            }
            // Disable defer for the top-level bucket call so the object
            // itself is collected. It will be re-enabled inside
            // collectZendObject's property loop.
            $this->defer_unseen_objects = false;
            $objects_store_bucket_context = $this->collectZendObjectPointer(
                $bucket,
                $map_ptr_base,
                $memory_locations,
                $dereferencer,
                $zend_type_reader,
                $context_pools,
                $memory_limit_error_details,
            );
            if ($objects_store_bucket_context === null) {
                continue;
            }
            if ($parent_node_id !== null) {
                $objects_store_bucket_context = $this->emitChildIfStreaming(
                    (string)$key,
                    $objects_store_bucket_context,
                    $parent_node_id,
                );
                $this->flushPoolsIfStreaming();
            }
            $objects_store_context->add((string)$key, $objects_store_bucket_context);
        }
        $this->defer_unseen_objects = false;
        return $objects_store_context;
    }

    private function collectGlobalCallbacks(
        ZendExecutorGlobals $eg,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): GlobalCallbacksContext {
        $global_callbacks_context = new GlobalCallbacksContext();

        $this->collectGlobalCallbackField(
            $eg,
            'user_error_handler',
            'error_handler',
            $global_callbacks_context,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
        $this->collectGlobalCallbackField(
            $eg,
            'user_exception_handler',
            'exception_handler',
            $global_callbacks_context,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );

        return $global_callbacks_context;
    }

    /**
     * Read a handler zval directly from the remote process memory via a
     * separate process_vm_readv call, avoiding CData struct navigation on
     * the large zend_executor_globals buffer (which can trigger FFI issues
     * on certain architectures like ARM).
     */
    private function collectGlobalCallbackField(
        ZendExecutorGlobals $eg,
        string $eg_field_name,
        string $link_name,
        GlobalCallbacksContext $context,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): void {
        [$offset,] = $zend_type_reader->getOffsetAndSizeOfMember(
            'zend_executor_globals',
            $eg_field_name,
        );
        $zval_size = $zend_type_reader->sizeOf('zval');
        $zval_pointer = new Pointer(
            Zval::class,
            $eg->getPointer()->address + $offset,
            $zval_size,
        );
        $zval = $dereferencer->deref($zval_pointer);
        if ($zval->isUndef()) {
            return;
        }
        $handler_context = $this->collectZval(
            $zval,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
        if ($handler_context !== null) {
            $context->add($link_name, $handler_context);
        }
    }

    private function collectModules(
        ?int $bg_address,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ModulesContext {
        $modules_context = new ModulesContext();

        if ($bg_address === null) {
            return $modules_context;
        }

        $standard_context = $this->collectStandardModule(
            $bg_address,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
        $modules_context->add('standard', $standard_context);

        return $modules_context;
    }

    private function collectStandardModule(
        int $bg_address,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): StandardModuleContext {
        $standard_context = new StandardModuleContext();

        $bg_pointer = new Pointer(
            PhpBasicGlobals::class,
            $bg_address,
            $zend_type_reader->sizeOf('php_basic_globals'),
        );
        /** @var PhpBasicGlobals $bg */
        $bg = $dereferencer->deref($bg_pointer);

        if ($bg->user_shutdown_function_names === null) {
            return $standard_context;
        }

        $shutdown_table = $dereferencer->deref($bg->user_shutdown_function_names);
        $entry_size = $zend_type_reader->sizeOf('php_shutdown_function_entry');

        foreach ($shutdown_table->getItemIterator($dereferencer) as $key => $zval) {
            if ($zval->getType() !== 'IS_PTR') {
                continue;
            }
            $entry_pointer = new Pointer(
                PhpShutdownFunctionEntry::class,
                $zval->value->lval,
                $entry_size,
            );
            try {
                $entry = $dereferencer->deref($entry_pointer);
                $callable_context = $this->collectShutdownFunctionCallable(
                    $entry,
                    $map_ptr_base,
                    $dereferencer,
                    $zend_type_reader,
                    $memory_locations,
                    $context_pools,
                    $memory_limit_error_details,
                );
                if ($callable_context !== null) {
                    $standard_context->add('shutdown_function[' . $key . ']', $callable_context);
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $standard_context;
    }

    private function collectShutdownFunctionCallable(
        PhpShutdownFunctionEntry $entry,
        int $map_ptr_base,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
        MemoryLocations $memory_locations,
        ContextPools $context_pools,
        ?MemoryLimitErrorDetails $memory_limit_error_details,
    ): ?ReferenceContext {
        if ($zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V81)) {
            // v70-v80: function_name is an inline zval
            $callable_zval = $entry->getFunctionNameDirect();
        } elseif ($zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V85)) {
            // v81-v84: function_name is in fci.function_name
            $callable_zval = $entry->getFunctionNameFromFci();
        } else {
            // v85+: callable is in fci_cache.closure
            $closure_pointer = $entry->getClosureFromFciCache();
            if ($closure_pointer === null) {
                return null;
            }
            return $this->collectZendObjectPointer(
                $closure_pointer,
                $map_ptr_base,
                $memory_locations,
                $dereferencer,
                $zend_type_reader,
                $context_pools,
                $memory_limit_error_details,
            );
        }

        if ($callable_zval->isUndef()) {
            return null;
        }
        return $this->collectZval(
            $callable_zval,
            $map_ptr_base,
            $dereferencer,
            $zend_type_reader,
            $memory_locations,
            $context_pools,
            $memory_limit_error_details,
        );
    }
}
