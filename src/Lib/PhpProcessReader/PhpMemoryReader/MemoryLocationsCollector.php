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
use Reli\Lib\PhpInternals\Types\Zend\ZendVmStack;
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
use Reli\Lib\Dwarf\NativeSymbolResolver;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\File\PathResolver\ProcessPathResolver;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector\NativeSymbolResolverAdapter;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\Detector\SymbolResolverInterface;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Lexbor\LexborScanRangeFinder;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Lexbor\LexborStateScanner;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\LexborStateContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ModulesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpTsrmLsCacheFinder;
use Reli\Lib\PhpProcessReader\PhpZendMemoryManagerChunkFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMapCreatorInterface;
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
        private ProcessMemoryMapCreatorInterface $process_memory_map_creator,
        private BinaryAnalysisCache $binary_analysis_cache,
        private ProcessPathResolver $process_path_resolver,
        private ?PhpTsrmLsCacheFinder $tsrm_ls_cache_finder = null,
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
     * @param bool $disable_bin_walk When true, skip the ZendMM bin
     *     walker that powers the orphan-allocation analysis pipeline
     *     (per-bin histogram, periodic groups, shape detection,
     *     region map). Reclaims the ~17% wall-time the walker adds at
     *     analyze time on heaps where the orphan-allocation features
     *     aren't needed.
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
        bool $disable_bin_walk = false,
        ?int $module_registry_address = null,
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

        // Auto-register every emitted Context's attached locations into the
        // address_map. The manual `address_map[$addr] = $node_id` writes
        // scattered across Jobs only cover the primary "registerable" address
        // each Job knows about — sub-allocations that hang off the same
        // Context (op_array header/body, object handlers, …) used to slip
        // through and confuse downstream reachability checks (e.g. the bin
        // walker's `reachable_count` stat). Existing explicit writes are
        // left in place so addresses that aren't on the Context (aliases
        // like the input pointer when it differs from the object's true
        // slot) keep their mapping.
        $analyzer->setOnNodeAssigned(
            static function (int $node_id, ReferenceContext $context) use ($ctx): void {
                foreach ($context->getLocations() as $location) {
                    $ctx->address_map[$location->address] = $node_id;
                }
            }
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
        // Pre-build the modules context so we can attach ext/uri's
        // lexbor branch under it before EmitModulesJob emits the root.
        // The scan itself happens after the queue is set up but before
        // the DFS begins; lexbor data is independent of EG/CG state.
        $modules_context = new ModulesContext();
        $this->maybeAttachLexborState(
            $process_specifier,
            $target_php_settings,
            $zend_type_reader,
            $modules_context,
            $memory_locations,
            $chunk_memory_locations,
            $huge_memory_locations,
        );

        $queue->push(new Collector\Job\EmitRegularListJob($eg->regular_list));
        $queue->push(new Collector\Job\EmitObjectsStoreJob($eg->objects_store));
        $queue->push(new Collector\Job\EmitCallFramesJob($eg));
        $queue->push(new Collector\Job\EmitModulesJob($bg_address, $modules_context));
        $queue->push(new Collector\Job\EmitGlobalCallbacksJob($eg));
        $queue->push(new Collector\Job\EmitIncludedFilesJob($eg->included_files));
        $queue->push(new Collector\Job\EmitGlobalConstantsJob($zend_constants));
        $queue->push(new Collector\Job\EmitFunctionTableJob($function_table));
        $queue->push(new Collector\Job\EmitClassTableJob($class_table));
        $queue->push(new Collector\Job\EmitGlobalVariablesJob($eg->symbol_table));
        $queue->push(new Collector\Job\EmitInternedStringsJob($cg->interned_strings));

        // Walk module globals HashTables for extensions known to hold
        // zend_strings unreachable from EG/CG roots (currently only
        // ext/phar). Without this, the bulk of bin8/bin16 zend_string
        // slots on phar-distributed CLI tools (phpactor, Symfony
        // Console, etc.) show up as unaccounted bytes in the
        // analyzed-percentage gap.
        if ($module_registry_address !== null) {
            try {
                $module_registry_pointer = new Pointer(
                    \Reli\Lib\PhpInternals\Types\Zend\ZendArray::class,
                    $module_registry_address,
                    $zend_type_reader->sizeOf('zend_array'),
                );
                $module_registry = $dereferencer->deref($module_registry_pointer);
                $queue->push(new Collector\Job\EmitModuleGlobalsHashTablesJob(
                    $module_registry,
                    $modules_context,
                ));
            } catch (\Throwable $e) {
                Log::debug('failed to push EmitModuleGlobalsHashTablesJob', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }

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
        //
        // The process memory map (when available) lets
        // FunctionPointerDetector resolve `.text` pointers to module
        // names — the validator's "is this libuv or libphp?" question.
        // Both sources are best-effort; bin walker failures must not
        // abort the analyze run.
        $process_memory_map = null;
        try {
            $process_memory_map = $this->process_memory_map_creator
                ->getProcessMemoryMap($pid);
        } catch (\Throwable $e) {
            Log::debug('process memory map fetch failed', ['exception' => $e]);
        }
        // Build a symbol resolver only when the process memory map is
        // available AND at least one named module is reachable through
        // the process path resolver. Both live (`/proc/<pid>/root`) and
        // offline (`--dependency-root` MappedPathResolver) paths land
        // here uniformly; the only reason to bail is "rdump from
        // different host without --dependency-root" — module-level
        // resolution still works in that case.
        $symbol_resolver = $this->buildSymbolResolver($pid, $process_memory_map);
        $bin_walk_result = null;
        if (!$disable_bin_walk) {
            try {
                $bin_walk_result = (new ZendMmBinWalker($this->memory_reader))->walk(
                    $pid,
                    $zend_mm_main_chunk,
                    $dereferencer,
                    $ctx->address_map,
                    $process_memory_map,
                    $symbol_resolver,
                );
            } catch (\Throwable $e) {
                Log::debug('ZendMmBinWalker failed', ['exception' => $e]);
            }
        }

        // ext/uri's lexbor branch was already attached to $modules_context
        // before the queue ran (see maybeAttachLexborState), so it's been
        // emitted as a child of `modules` along with `standard`.

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

    /**
     * Build the symbol resolver used by FunctionPointerDetector.
     *
     * Routes through the autowired {@see ProcessPathResolver}, the same
     * primitive {@see \Reli\Inspector\MemoryDump\DumpFileMemoryReader}
     * uses to look up paths recorded inside an rdump. Three cases shake
     * out from the same code path:
     *
     *  - Live target: the default
     *    {@see \Reli\Lib\File\PathResolver\ContainerAwarePathResolver}
     *    maps `/<path>` to `/proc/{pid}/root/<path>` (kernel-provided
     *    chroot view, follows mount namespaces correctly).
     *  - Offline rdump with `--dependency-root <dir>`:
     *    {@see \Reli\Lib\File\PathResolver\MappedPathResolver} (set up
     *    by MemoryDumpReaderFactory) maps `/<path>` to `<dir>/<path>`,
     *    matching the coredump-reader convention.
     *  - Offline rdump without `--dependency-root`: same MappedPathResolver
     *    with no mappings, so `/<path>` resolves unchanged — the typical
     *    "analyzing on the same machine where the binaries live"
     *    workflow falls into this branch automatically.
     *
     * Returns null when the path resolver can't surface any binary
     * (e.g. analyzing an rdump from a different host without
     * `--dependency-root`) so the per-slot scan doesn't thrash through
     * file-not-found syscalls.
     */
    private function buildSymbolResolver(
        int $pid,
        ?ProcessMemoryMap $process_memory_map,
    ): ?SymbolResolverInterface {
        if ($process_memory_map === null) {
            return null;
        }
        $process_root = $this->probeProcessRoot($pid);
        if (!$this->hasReachableModule($process_memory_map, $process_root)) {
            return null;
        }
        try {
            $native = new NativeSymbolResolver(
                memoryMap: $process_memory_map,
                processRoot: $process_root,
                binaryAnalysisCache: $this->binary_analysis_cache,
            );
            return new NativeSymbolResolverAdapter($native);
        } catch (\Throwable $e) {
            Log::debug('symbol resolver build failed', ['exception' => $e]);
            return null;
        }
    }

    /**
     * Derive the binary-lookup prefix the way NativeSymbolResolver
     * expects (a string concatenated to a leading `/<path>`). Probes
     * the {@see ProcessPathResolver} with a sentinel and strips the
     * sentinel back off — works uniformly for the live
     * `/proc/<pid>/root` resolver and the offline MappedPathResolver
     * with `[/ => --dependency-root]`.
     */
    private function probeProcessRoot(int $pid): string
    {
        $sentinel = '/__reli_probe__';
        $resolved = $this->process_path_resolver->resolve($pid, $sentinel);
        if (str_ends_with($resolved, $sentinel)) {
            return substr($resolved, 0, -strlen($sentinel));
        }
        return '';
    }

    /**
     * Cheap check: is at least one named module from the memory map
     * actually readable through the chosen `process_root`? When false,
     * the symbol resolver would just thrash through file-not-found
     * per slot — better to bail and let FunctionPointerDetector fall
     * back to module-level labels.
     */
    private function hasReachableModule(
        ProcessMemoryMap $process_memory_map,
        string $process_root,
    ): bool {
        $candidates = $process_memory_map->findByNameRegex('\\.so\\b|/(php|php-fpm|frankenphp|httpd|apache2)$');
        if ($candidates === []) {
            // No real-binary mappings (e.g. CLI with statically linked
            // PHP). We can still try with the executable mapping as a
            // fallback — those always exist for any live process.
            $candidates = $process_memory_map->findByNameRegex('^/');
        }
        foreach (array_slice($candidates, 0, 4) as $area) {
            $name = $area->name;
            if ($name === '' || $name[0] === '[') {
                continue;
            }
            $path = $process_root . $name;
            if (@is_readable($path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Try to scan ext/uri's lexbor state and attach the matched
     * MemoryLocations under the supplied `ModulesContext` as a child
     * branch named `uri` (matching the module name reported by `php -m`).
     *
     * Best-effort and silently no-ops when:
     *   - the target PHP version is < 8.5 (ext/uri does not exist);
     *   - the process memory map can't be fetched;
     *   - the binary's writable VMA can't be located;
     *   - the scanner finds no fingerprint matches.
     *
     * @param TargetPhpSettings<VersionDecided> $target_php_settings
     */
    private function maybeAttachLexborState(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        ZendTypeReader $zend_type_reader,
        ModulesContext $modules_context,
        \Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations $memory_locations,
        \Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations $chunk_memory_locations,
        \Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MemoryLocations $huge_memory_locations,
    ): void {
        if ($zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V85)) {
            return;
        }
        try {
            $process_memory_map = $this->process_memory_map_creator
                ->getProcessMemoryMap($process_specifier->pid);
        } catch (\Throwable $e) {
            Log::debug('lexbor scan: process_memory_map fetch failed', ['exception' => $e]);
            return;
        }
        $ranges = LexborScanRangeFinder::find(
            $process_specifier,
            $target_php_settings,
            $process_memory_map,
            $this->tsrm_ls_cache_finder,
        );
        if ($ranges === []) {
            return;
        }
        $state_context = new LexborStateContext();
        try {
            $emitted = (new LexborStateScanner($this->memory_reader))->scan(
                $process_specifier->pid,
                $ranges,
                $process_memory_map,
                $memory_locations,
                $state_context,
                [$chunk_memory_locations, $huge_memory_locations],
            );
        } catch (\Throwable $e) {
            Log::debug('LexborStateScanner failed', ['exception' => $e]);
            return;
        }
        if ($emitted > 0) {
            $modules_context->add('uri', $state_context);
        }
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

        $vm_stack_struct_size = $ctx->zend_type_reader->sizeOf(ZendVmStack::getCTypeName());
        $first_stack = true;
        foreach ($last_vm_stack->iterateStackChain($ctx->dereferencer) as $vm_stack) {
            // _zend_vm_stack::top is initialised to the arena base when the arena is
            // created and is later bumped UP to the high-water mark when a new arena
            // is allocated on top of this one (zend_vm_stack_extend saves the live
            // EG(vm_stack_top) into the outgoing arena's struct->top). After the
            // memory_limit fatal, EG(vm_stack_top) gets unwound back to the shutdown
            // handler's frame, which can sit LOWER than struct->top in the same
            // arena. Either of struct->top or EG(vm_stack_top) can be the higher one,
            // so take the max and always scan from the arena's base.
            $struct_top = $vm_stack->top?->address;
            if (is_null($struct_top)) {
                if (!$first_stack) {
                    break;
                }
                $struct_top = 0;
            }
            $arena_base = $vm_stack->pointer->address + $vm_stack_struct_size;
            if ($first_stack) {
                $first_stack = false;
                $hwm = max($struct_top, $eg->vm_stack_top->address);
            } else {
                $hwm = $struct_top;
            }
            // Belt-and-braces: clamp to the arena's allocation
            // boundary so a corrupted dump (or a state we haven't
            // anticipated) can't ask us to materialize a multi-GB
            // pointer array.
            $arena_end = $vm_stack->end?->address;
            if ($arena_end !== null) {
                $hwm = min($hwm, $arena_end);
            }
            $materialized_vm_stack = $vm_stack->materializeRangeAsPointerArray(
                $ctx->dereferencer,
                $arena_base,
                $hwm,
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
                    // A true chain root has prev_execute_data == NULL. getRootFrame()
                    // may also return early when max_depth is reached, in which case
                    // the returned frame still has a non-null prev — that's a false
                    // candidate. Originally this was checked indirectly by comparing
                    // the candidate root's address against root_vm_stack->top, but
                    // _zend_vm_stack::top gets bumped to the high-water-mark when a
                    // new arena is allocated above this one, so that comparison only
                    // works for the unextended single-arena case.
                    $reached_true_root = is_null($root_execute_data_candidate->prev_execute_data);
                    $legacy_top_match = $root_vm_stack->top->address === $root_execute_data_candidate->getPointer()->address;
                    if (!$reached_true_root && !$legacy_top_match) {
                        continue;
                    }
                    Log::debug('root candidate frame found', ['frame_address' => $root_execute_data_candidate->getPointer()->address]);

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
