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

use Reli\Lib\PhpInternals\Types\Phar\PharArchiveData;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendModuleEntry;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ModulesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\StandardModuleContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Walk inline HashTables found inside selected PHP extensions' module
 * globals structs.
 *
 * Some extensions hold large numbers of zend_strings in module-globals
 * HashTables that the standard EG/CG root walk doesn't see. ext/phar
 * is the canonical case: `phar_fname_map`, `phar_alias_map`,
 * `phar_persist_map`, and friends catalog every entry inside every
 * loaded .phar, and on a phpactor / Symfony Console / etc. workload
 * those tables hold tens of thousands of file-path zend_strings that
 * the bin walker enumerates but the analyzer never reaches. Closing
 * that path eliminates the bulk of the bin8/bin16 zend_string gap.
 *
 * We do this without parsing the per-extension globals struct layout
 * — there's no headers exposing it, and the struct evolves between
 * PHP versions. Instead we scan the globals region at 8-byte
 * alignment, deref each candidate as a `zend_array`, and accept if
 * `gc.type_info` low byte is IS_ARRAY (7). False positives that
 * survive the type check are deflected by {@see EmitArrayJob}'s own
 * validity guards (catches on bad nTableMask, etc.).
 */
final class EmitModuleGlobalsHashTablesJob implements CollectorJob
{
    private const IS_ARRAY = 7;
    /** Stop scanning past this many bytes into the globals struct. */
    private const MAX_SCAN_BYTES = 4096;
    /** A zend_array fits on 8-byte alignment within a struct. */
    private const SCAN_STEP = 8;

    public function __construct(
        private ZendArray $module_registry,
        private ?ModulesContext $modules_context = null,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        // The per-archive manifest walker needs the
        // PharArchiveDataLayout generated layout, which today only
        // ships for v84. Skip on other versions until those headers
        // grow `_phar_archive_data_truncated` and the layout gets
        // regenerated.
        if ($ctx->zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V84)) {
            return;
        }

        // Today we only walk phar. Other modules can be added once
        // they show up as gap contributors in real workloads.
        // module_registry keys are lowercase — module_entry->name
        // preserves the original casing but the HashTable key is
        // lowercased on register (see zend_register_internal_module).
        $targets = ['phar'];

        foreach ($targets as $name) {
            try {
                $this->walkModuleGlobals($name, $ctx, $queue);
            } catch (\Throwable $e) {
                \Reli\Lib\Log\Log::debug(
                    'failed to walk ' . $name . ' module globals',
                    ['exception' => $e->getMessage()]
                );
            }
        }
    }

    private function walkModuleGlobals(
        string $module_name,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        $bucket = $this->module_registry->findByKey($ctx->dereferencer, $module_name);
        if ($bucket === null) {
            return;
        }
        // CLAUDE.md FFI CData lifetime rule: re-deref the val zval
        // through Pointer rather than reading $bucket->val (a sub-view).
        $val_zval = $ctx->dereferencer->deref($bucket->val->getPointer());
        if ($val_zval->getType() !== 'IS_PTR') {
            return;
        }
        $entry_addr = $val_zval->value->lval;
        if ($entry_addr === 0) {
            return;
        }
        $entry_pointer = new Pointer(
            ZendModuleEntry::class,
            $entry_addr,
            $ctx->zend_type_reader->sizeOf('zend_module_entry'),
        );
        $entry = $ctx->dereferencer->deref($entry_pointer);
        if ($entry->zts) {
            // ZTS stores `ts_rsrc_id*` in globals_ptr and needs TSRM
            // offset resolution; defer until a ZTS gap motivates it.
            return;
        }
        $globals_size = $entry->globals_size;
        $globals_ptr = $entry->globals_ptr;
        if ($globals_ptr === 0 || $globals_size === 0) {
            return;
        }
        $scan_bytes = min($globals_size, self::MAX_SCAN_BYTES);
        $array_size = $ctx->zend_type_reader->sizeOf('zend_array');

        // Hang discovered HashTables under a synthetic
        // `module_globals[<name>]` branch — the existing ModulesContext
        // is reused if the EmitModulesJob already wired one in, else
        // we emit independently so the subtree doesn't get dropped.
        $modules_context = $this->modules_context ?? new ModulesContext();
        $host_context = new StandardModuleContext();
        $modules_context->add($module_name . '_globals', $host_context);
        $parent_memo = $host_context->getMemoNodeId();
        if ($parent_memo === null) {
            $parent_id = $ctx->emitNode(
                $host_context,
                null,
                'module_globals[' . $module_name . ']',
            );
        } else {
            $parent_id = $parent_memo < 0 ? -$parent_memo - 1 : $parent_memo;
        }

        for ($offset = 0; $offset + $array_size <= $scan_bytes; $offset += self::SCAN_STEP) {
            $candidate_addr = $globals_ptr + $offset;
            if (isset($ctx->address_map[$candidate_addr])) {
                continue;
            }
            try {
                $ht_pointer = new Pointer(
                    ZendArray::class,
                    $candidate_addr,
                    $array_size,
                );
                $ht = $ctx->dereferencer->deref($ht_pointer);
            } catch (\Throwable) {
                continue;
            }
            // gc.type_info low byte == IS_ARRAY filters out 99%+ of
            // random struct fields. Plausible refcount guards the
            // remaining false positives.
            $type_info = $ht->gc->type_info;
            if (($type_info & 0xFF) !== self::IS_ARRAY) {
                continue;
            }
            if ($ht->gc->refcount > 0x0FFF_FFFF) {
                continue;
            }
            try {
                EmitArrayJob::processZendArray(
                    $ht,
                    $parent_id >= 0 ? $parent_id : null,
                    sprintf('ht@+%d', $offset),
                    EdgeStrength::Strong,
                    $ctx,
                    $queue,
                );
            } catch (\Throwable) {
                continue;
            }
            if ($module_name === 'phar') {
                $this->walkPharArchives($ht, $parent_id, $ctx, $queue);
            }
        }
    }

    /**
     * For each IS_PTR bucket value in $ht (which, when $ht is one of
     * phar's top-level globals tables, is a `phar_archive_data*`),
     * deref as PharArchiveData and walk its inline `manifest`
     * HashTable. processZendArray takes it from there — the manifest's
     * string keys (per-entry file paths) and string values get emitted
     * the usual way.
     *
     * IS_PTR values that aren't phar_archive_data pointers are
     * deflected by the manifest's IS_ARRAY gc.type_info check (the
     * misinterpreted memory at +OFFSET_MANIFEST almost never has the
     * IS_ARRAY low byte).
     */
    private function walkPharArchives(
        ZendArray $ht,
        int $parent_id,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        try {
            $iterator = $ht->getItemIterator($ctx->dereferencer);
        } catch (\Throwable) {
            return;
        }
        $archive_size = $ctx->zend_type_reader->sizeOf('phar_archive_data_truncated');
        foreach ($iterator as $zval) {
            try {
                if ($zval->getType() !== 'IS_PTR') {
                    continue;
                }
                $archive_addr = $zval->value->lval;
                if ($archive_addr === 0) {
                    continue;
                }
                $archive_pointer = new Pointer(
                    PharArchiveData::class,
                    $archive_addr,
                    $archive_size,
                );
                $archive = $ctx->dereferencer->deref($archive_pointer);
                // Each inline HashTable inside phar_archive_data —
                // walking surfaces the per-archive zend_strings the
                // standard root walk can't reach.
                $this->walkArchiveTable($archive->manifest, 'manifest', $archive_addr, $parent_id, $ctx, $queue);
                $this->walkArchiveTable($archive->virtual_dirs, 'virtual_dirs', $archive_addr, $parent_id, $ctx, $queue);
                $this->walkArchiveTable($archive->mounted_dirs, 'mounted_dirs', $archive_addr, $parent_id, $ctx, $queue);
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function walkArchiveTable(
        ZendArray $ht,
        string $label,
        int $archive_addr,
        int $parent_id,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        try {
            $addr = $ht->getPointer()->address;
            if (isset($ctx->address_map[$addr])) {
                return;
            }
            if (($ht->gc->type_info & 0xFF) !== self::IS_ARRAY) {
                return;
            }
            if ($ht->gc->refcount > 0x0FFF_FFFF) {
                return;
            }
            EmitArrayJob::processZendArray(
                $ht,
                $parent_id >= 0 ? $parent_id : null,
                sprintf('phar_%s@0x%x', $label, $archive_addr),
                EdgeStrength::Strong,
                $ctx,
                $queue,
            );
        } catch (\Throwable) {
            // Bad pointer / uninitialised HashTable — skip silently.
        }
    }
}
