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

use PhpCast\Cast;
use Reli\Inspector\Settings\MemoryProfilerSettings\MemoryLimitErrorDetails;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\Log\Log;
use Reli\Lib\PhpInternals\VersionedPointedTypeResolver;
use Reli\Lib\PhpInternals\Types\Zend\ZendCastedTypeProvider;
use Reli\Lib\PhpInternals\Types\Zend\ZendCompilerGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecuteData;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmChunk;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpInternals\ZendTypeReaderCreator;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\BinWalkResult;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\ZendMmBinWalker;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\VmStackMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArenaMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmChunkMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendMmHugeListMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\CallFramesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\UserFunctionDefinitionContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\RegionAnalyzer\RegionBoundaries;
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

    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ZendTypeReaderCreator $zend_type_reader_creator,
        private PhpZendMemoryManagerChunkFinder $chunk_finder,
    ) {
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

    /**
     * @param TargetPhpSettings<VersionDecided> $target_php_settings
     * @param \Reli\Inspector\MemoryDump\FastPath\FastPathReader|null $fast_path
     *     Optional fast-path reader for dump analysis. When provided,
     *     hot collector jobs use string-buffer reads instead of FFI.
     */
    public function collectAll(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        int $eg_address,
        int $cg_address,
        ?MemoryLimitErrorDetails $memory_limit_error_details = null,
        ?int $bg_address = null,
        ?ContextTreeSink $sink = null,
        ?\Reli\Inspector\MemoryDump\FastPath\FastPathReader $fast_path = null,
    ): CollectedMemories {
        $pid = $process_specifier->pid;
        $php_version = $target_php_settings->php_version;
        $dereferencer = $this->getDereferencer($pid, $php_version, enable_cache: true);
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

        $memory_locations = MemoryLocations::createLightweight();
        $chunk_memory_locations = new MemoryLocations();

        $zend_mm_main_chunk = $dereferencer->deref($main_chunk_header_pointer);
        $walked_chunk_count = 0;
        $chunks_total_free_bytes = 0;
        $chunks_mostly_empty_count = 0;
        // Threshold: chunks that are at least 90% free (so only a few pages
        // pinning an otherwise-empty chunk).
        $mostly_empty_free_pages_threshold = intdiv(ZendMmChunk::SIZE / ZendMmChunk::PAGE_SIZE * 9, 10);
        foreach ($zend_mm_main_chunk->iterateChunks($dereferencer) as $chunk) {
            $chunk_memory_location = ZendMmChunkMemoryLocation::fromZendMmChunk($chunk);
            $chunk_memory_locations->add(
                $chunk_memory_location
            );
            $free_pages = $chunk->free_pages;
            $chunks_total_free_bytes += $free_pages * ZendMmChunk::PAGE_SIZE;
            if ($free_pages >= $mostly_empty_free_pages_threshold) {
                $chunks_mostly_empty_count++;
            }
            $walked_chunk_count++;
        }

        $huge_memory_locations = new MemoryLocations();
        $huge_total_bytes = 0;
        foreach ($zend_mm_main_chunk->heap_slot->iterateHugeList($dereferencer) as $huge_list) {
            $huge_memory_locations->add(
                ZendMmChunkMemoryLocation::fromZendMmHugeList($huge_list)
            );
            $memory_locations->add(
                ZendMmHugeListMemoryLocation::fromZendMmHugeList($huge_list)
            );
            $huge_total_bytes += $huge_list->size;
        }

        $memory_get_usage_size = $zend_mm_main_chunk->heap_slot->size;
        $memory_get_usage_real_size = $zend_mm_main_chunk->heap_slot->real_size;
        $memory_get_peak_usage = $zend_mm_main_chunk->heap_slot->peak;
        $memory_limit = $zend_mm_main_chunk->heap_slot->limit;
        $chunks_count = $zend_mm_main_chunk->heap_slot->chunks_count;
        $peak_chunks_count = $zend_mm_main_chunk->heap_slot->peak_chunks_count;
        $cached_chunks_count = $zend_mm_main_chunk->heap_slot->cached_chunks_count;
        $cached_chunks_size = $cached_chunks_count * ZendMmChunk::SIZE;
        $last_chunks_delete_boundary = $zend_mm_main_chunk->heap_slot->last_chunks_delete_boundary;
        $last_chunks_delete_count = $zend_mm_main_chunk->heap_slot->last_chunks_delete_count;

        // Warn if chunk walk was incomplete (corrupt next pointer or
        // chunks outside dump region). real_size is ZendMM's bookkeeping
        // for everything currently mmap'd from the OS — active chunks +
        // cached (held-for-reuse) chunks + huge allocations — so the
        // captured-bytes side has to include all three or single huge
        // allocations falsely trip the guard.
        $walked_chunk_bytes = $walked_chunk_count * ZendMmChunk::SIZE;
        $captured_bytes = $walked_chunk_bytes + $cached_chunks_size + $huge_total_bytes;
        if ($memory_get_usage_real_size > 0 && $captured_bytes < $memory_get_usage_real_size / 2) {
            // Cast to float once per value so the byte→MB division returns
            // a float without tripping Psalm's strict int/float operand
            // check (see psalm-058). Cold diagnostic path, called at most
            // once per analyze run.
            $walked_mb = number_format(Cast::toFloat($walked_chunk_bytes) / 1024.0 / 1024.0, 1);
            $cached_mb = number_format(Cast::toFloat($cached_chunks_size) / 1024.0 / 1024.0, 1);
            $huge_mb = number_format(Cast::toFloat($huge_total_bytes) / 1024.0 / 1024.0, 1);
            $captured_mb = number_format(Cast::toFloat($captured_bytes) / 1024.0 / 1024.0, 1);
            $real_mb = number_format(Cast::toFloat($memory_get_usage_real_size) / 1024.0 / 1024.0, 1);
            fwrite(STDERR, "WARNING: ZendMM chunk walk incomplete — captured {$captured_mb} MB"
                . " ({$walked_mb} MB in {$walked_chunk_count} chunks"
                . " + {$cached_mb} MB cached + {$huge_mb} MB huge),"
                . " but real_size is {$real_mb} MB."
                . " Some chunks may be outside the dump region.\n");
        }

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

        // Build RegionBoundaries now — chunk/huge/vm_stack/compiler_arena are known.
        // Set on sink so emitNode writes region inline (no backfill needed).
        $region_boundaries = new RegionBoundaries(
            $chunk_memory_locations,
            $huge_memory_locations,
            $vm_stack_memory_locations,
            $compiler_arena_memory_locations,
        );

        $context_pools = ContextPools::createDefault();

        // If no sink was provided, create an internal ArrayContextTreeSink
        // so the iterative code path always has a sink to emit to.
        if ($sink === null) {
            $sink = new \Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ArrayContextTreeSink();
        }
        if ($sink instanceof \Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink) {
            $sink->setRegionBoundaries($region_boundaries);
        }
        if ($sink instanceof \Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\BinaryContextTreeSink) {
            $sink->setRegionBoundaries($region_boundaries);
        }
        $analyzer = new ContextAnalyzer();

        assert(!is_null($eg->function_table));
        assert(!is_null($eg->class_table));
        assert(!is_null($eg->zend_constants));

        $function_table = $dereferencer->deref($eg->function_table);
        $class_table = $dereferencer->deref($eg->class_table);
        $zend_constants = $dereferencer->deref($eg->zend_constants);

        // Create the shared collector context
        $ctx = new Collector\CollectorContext(
            $dereferencer,
            $zend_type_reader,
            $sink,
            $analyzer,
            $memory_locations,
            $context_pools,
            $cg->map_ptr_base,
            $memory_limit_error_details,
            $vm_stack_memory_locations,
            $chunk_memory_locations,
            $fast_path,
        );

        // Create the job queue
        $queue = new Collector\JobQueue();

        // Seed the queue with root branches in reverse order for LIFO DFS.
        // Last pushed = first processed.
        //
        // Ordering matters: when two branches reference the same object
        // (e.g. symbol_table is also a call frame's variable table),
        // the branch processed FIRST gets the "real" tree structure;
        // later branches get a reference edge to the existing node.
        //
        // Process "definition" branches before "usage" branches so the
        // canonical tree lives under the named globals/tables, not
        // buried inside a call frame.
        $queue->push(new Collector\Job\EmitRegularListJob($eg->regular_list));
        $queue->push(new Collector\Job\EmitObjectsStoreJob($eg->objects_store));
        $queue->push(new Collector\Job\EmitCallFramesJob($eg));
        $queue->push(new Collector\Job\EmitModulesJob($bg_address));
        $queue->push(new Collector\Job\EmitGlobalCallbacksJob($eg));
        $queue->push(new Collector\Job\EmitIncludedFilesJob($eg->included_files));
        $queue->push(new Collector\Job\EmitGlobalConstantsJob($zend_constants));
        $queue->push(new Collector\Job\EmitFunctionTableJob($function_table));
        $queue->push(new Collector\Job\EmitClassTableJob($class_table));
        $queue->push(new Collector\Job\EmitGlobalVariablesJob($eg->symbol_table));
        $queue->push(new Collector\Job\EmitInternedStringsJob($cg->interned_strings));

        // Main iterative loop
        $drain_counter = 0;
        while (!$queue->isEmpty()) {
            $job = $queue->pop();
            assert($job !== null);
            try {
                $job->execute($ctx, $queue);
            } catch (\Throwable) {
                // Skip failed jobs (bad pointers, unmapped memory, etc.)
                // and continue processing remaining queue
            }
            // Periodically drain emitted contexts from the pools to
            // free memory. Once a context has been emitted and its
            // node_id recorded in address_map, the pool entry is no
            // longer needed — subsequent dedup hits address_map directly.
            if (++$drain_counter >= 100000) {
                $context_pools->drainEmittedToAddressMap($ctx->address_map);
                $drain_counter = 0;
            }
        }
        // Final drain for any remaining emitted contexts
        $context_pools->drainEmittedToAddressMap($ctx->address_map);

        // Post-processing: memory limit violation real call stack recovery.
        // This is a rare edge case that reconstructs the call stack from
        // VM stack scanning. It runs after the main loop because it needs
        // the memory_limit_error_function_context set during function collection.
        if (
            $memory_limit_error_details
            and !is_null($ctx->memory_limit_error_function_context)
        ) {
            $this->collectRealCallStackOnMemoryLimitViolation(
                $ctx->memory_limit_error_function_context,
                $memory_limit_error_details->max_challenge_depth,
                $eg,
                $ctx,
            );
        }

        // Walk the small-bin freelists + chunk page maps to recover the
        // per-bin live-allocation histogram and any periodic same-shape
        // groups. Foundation for the orphan-allocation analysis path
        // (see docs/internals/design-orphan-allocation-analysis.md).
        // The region map snapshot (B.2) is built unconditionally — it's
        // a couple of hundred entries even on heavy daemons, so the
        // cost is negligible and lets the comparison path emit
        // "+728 KiB at 0x..." callouts without round-tripping the rdump.
        //
        // Runs AFTER the DFS so we can hand the root walker's
        // `address_map` to the bin walker as the reachability filter
        // (design's "negative-evidence rule"). Without it, periodic
        // groups can't tell normal claimed data from orphan leaks.
        // Non-fatal — a failure here must not abort the analyze run.
        $bin_walk_result = null;
        try {
            $bin_walk_result = (new ZendMmBinWalker($this->memory_reader))->walk(
                $pid,
                $zend_mm_main_chunk,
                $dereferencer,
                $ctx->address_map,
            );
        } catch (\Throwable $e) {
            Log::debug('ZendMmBinWalker failed', ['exception' => $e]);
        }

        $context_pools->clear();

        return new CollectedMemories(
            $chunk_memory_locations,
            $huge_memory_locations,
            $vm_stack_memory_locations,
            $compiler_arena_memory_locations,
            $cached_chunks_size,
            $memory_locations,
            $memory_get_usage_size,
            $memory_get_usage_real_size,
            $memory_get_peak_usage,
            $memory_limit,
            $chunks_count,
            $peak_chunks_count,
            $cached_chunks_count,
            $last_chunks_delete_boundary,
            $last_chunks_delete_count,
            $chunks_total_free_bytes,
            $chunks_mostly_empty_count,
            $bin_walk_result,
        );
    }

    private function collectRealCallStackOnMemoryLimitViolation(
        UserFunctionDefinitionContext $memory_limit_error_function_context,
        int $max_challenge_depth,
        ZendExecutorGlobals $eg,
        Collector\CollectorContext $ctx,
    ): void {
        $op_array_address = $memory_limit_error_function_context->getOpArrayAddress();

        if (is_null($eg->vm_stack)) {
            return;
        }
        if (is_null($eg->vm_stack_top)) {
            return;
        }

        $last_vm_stack = $ctx->dereferencer->deref($eg->vm_stack);
        $root_vm_stack = $last_vm_stack->getRootStack($ctx->dereferencer);
        if (is_null($root_vm_stack->top)) {
            return;
        }

        $first_stack = true;
        foreach ($last_vm_stack->iterateStackChain($ctx->dereferencer) as $vm_stack) {
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
                $ctx->dereferencer,
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
                    $ctx->zend_type_reader->sizeOf('zend_execute_data')
                );
                try {
                    $execute_data_candidate = $ctx->dereferencer->deref($frame_candidate);
                    $root_execute_data_candidate = $execute_data_candidate->getRootFrame(
                        $ctx->dereferencer,
                        $max_challenge_depth,
                    );
                    if ($root_vm_stack->top->address !== $root_execute_data_candidate->getPointer()->address) {
                        continue;
                    }
                    Log::debug('root candidate frame found', ['frame_address' => $root_vm_stack->top->address]);

                    // Emit call_frames_context as root node
                    $call_frames_context = new CallFramesContext();
                    $root_node_id = $ctx->emitNode($call_frames_context, null, 'memory_limit_call_frames');
                    $parent = $root_node_id >= 0 ? $root_node_id : null;

                    // Collect frames and push as jobs
                    $frames = [];
                    $chain = $execute_data_candidate->iterateStackChain($ctx->dereferencer);
                    foreach ($chain as $frame_no => $execute_data) {
                        $frames[] = [(string)$frame_no, $execute_data];
                    }

                    // Create a local job queue and push in reverse order for DFS
                    $recovery_queue = new Collector\JobQueue();
                    for ($i = count($frames) - 1; $i >= 0; $i--) {
                        [$frame_key, $frame] = $frames[$i];
                        $recovery_queue->push(new Collector\Job\EmitCallFrameJob($frame, $parent, $frame_key));
                    }

                    // Run the recovery queue
                    while (!$recovery_queue->isEmpty()) {
                        $job = $recovery_queue->pop();
                        assert($job !== null);
                        try {
                            $job->execute($ctx, $recovery_queue);
                        } catch (\Throwable) {
                            // Skip failed jobs
                        }
                    }

                    return;
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
    }
}
