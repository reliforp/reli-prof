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
use Reli\Lib\PhpInternals\Types\Phar\PharEntryInfo;
use Reli\Lib\PhpInternals\Types\Phar\PharGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendModuleEntry;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MallocBufferMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\PhpStream\PhpStreamWalker;
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
 * `phar_persist_map`, `mime_types`, and the per-archive `manifest`,
 * `virtual_dirs`, `mounted_dirs` catalog every entry inside every
 * loaded .phar.
 *
 * For each supported extension we model the prefix of its globals
 * struct in the per-version C headers (`phar_globals_truncated` in
 * v84.h) and access the inline HashTables by name. An earlier
 * iteration scanned the globals region heuristically for IS_ARRAY
 * signatures; we keep the named-field path now because phar's layout
 * is stable enough to model and the scan was missing tables that sit
 * past intermediate bool / function-pointer fields (e.g. mime_types
 * at offset 456 in PHP 8.4).
 */
final class EmitModuleGlobalsHashTablesJob implements CollectorJob
{
    private const IS_ARRAY = 7;
    /** Hard ceiling for HashTable size fields. Real PHP HashTables in
     *  the wild stay well under this; anything past it is a strong
     *  signal we're staring at garbage bytes (uninitialised inline
     *  table, misaligned struct, etc.). 16M tops keeps any plausibly
     *  legitimate big table while cutting off the runaway-bucket-walk
     *  case that lets a bogus 4G nTableSize hand processZendArray a
     *  petabyte of work and OOM the analyzer. */
    private const MAX_SANE_HT_SIZE = 0x100_0000;
    /** Lower bound for arData — anything below this is almost
     *  certainly not a real user-space heap pointer. */
    private const MIN_USER_PTR     = 0x1_0000;
    /** x86_64 Linux user-space caps at 0x7fff_ffff_ffff. Above that
     *  is kernel space and `arData` shouldn't ever be there. */
    private const MAX_USER_PTR     = 0x7fff_ffff_ffff;

    public function __construct(
        private ZendArray $module_registry,
        private ?ModulesContext $modules_context = null,
        /** TSRM thread-local-storage cache base address. When set
         *  (and non-null), ZTS phar globals are resolved via the
         *  TSRM id-indexing path (see resolveZtsGlobalsAddress).
         *  null means NTS or "couldn't determine" — the walker
         *  silently skips ZTS phar in that case. */
        private ?int $tsrm_ls_cache_address = null,
    ) {
    }

    /**
     * Validity gate for HashTables we discovered by reading bytes out
     * of a module-globals struct. Inline HashTables aren't always
     * fully initialised (mime_types is persistent and may be half-set
     * up depending on RINIT state), and misaligned struct layouts
     * land bogus values on the same bytes. Returning false here is
     * the difference between skipping cleanly and feeding garbage
     * arData / nTableSize into processZendArray and walking
     * billions of fake buckets until we OOM.
     */
    private function looksLikeWalkableHashTable(ZendArray $ht): bool
    {
        if (($ht->gc->type_info & 0xFF) !== self::IS_ARRAY) {
            return false;
        }
        if ($ht->gc->refcount > 0x0FFF_FFFF) {
            return false;
        }
        $size = $ht->nTableSize;
        $used = $ht->nNumUsed;
        $elements = $ht->nNumOfElements;
        if ($size < 0 || $size > self::MAX_SANE_HT_SIZE) {
            return false;
        }
        if ($used < 0 || $used > $size) {
            return false;
        }
        if ($elements < 0 || $elements > $used) {
            return false;
        }
        // Empty HashTables are fine — arData may legitimately be NULL
        // (or a sentinel "uninitialised buckets" pointer). Only check
        // arData when we plan to iterate it.
        if ($used > 0 && $ht->arData !== null) {
            $arData_addr = $ht->arData->address;
            if ($arData_addr < self::MIN_USER_PTR || $arData_addr > self::MAX_USER_PTR) {
                return false;
            }
        }
        return true;
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        // The walker needs PharGlobalsLayout / PharArchiveDataLayout
        // generated layouts for the target PHP version. They ship
        // for v80 and up today; older versions (pre-phar_metadata_tracker,
        // different zend_array layouts) need separate truncated
        // structs in their per-version headers and a generator
        // re-run to land.
        if ($ctx->zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V80)) {
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
            // ZTS: globals_ptr holds a `ts_rsrc_id*` (pointer to int).
            // Resolve the per-thread globals address via the id-indexing
            // path, matching what TsrmGlobalsResolver::resolveById
            // does for EG / CG / SG.
            $globals_ptr = $this->resolveZtsGlobalsAddress($entry->globals_ptr, $ctx);
        } else {
            $globals_ptr = $entry->globals_ptr;
        }
        if ($globals_ptr === 0) {
            return;
        }

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

        // Today the only module we model is phar — deref via the
        // PharGlobals layout struct and walk each named HashTable. Other
        // extensions will follow the same pattern when they show up as
        // a gap contributor: model the globals struct, deref, walk.
        if ($module_name === 'phar') {
            $this->walkPharGlobals($globals_ptr, $parent_id, $ctx, $queue);
        }
    }

    /**
     * Resolve a ZTS module's per-thread globals address from a
     * `ts_rsrc_id*` slot. Mirrors the inline math in
     * TsrmGlobalsResolver::resolveById — we duplicate it here
     * because that resolver is keyed on libphp symbol names
     * (executor_globals etc.) and the per-thread cache offset for
     * phar comes out of phar.so's own BSS, which the symbol reader
     * isn't wired up to read today.
     *
     * Returns 0 on any failure — caller treats that as "skip this
     * module" same as a NULL NTS pointer.
     */
    private function resolveZtsGlobalsAddress(
        int $id_ptr_address,
        CollectorContext $ctx,
    ): int {
        if ($this->tsrm_ls_cache_address === null) {
            return 0;
        }
        if ($id_ptr_address === 0) {
            return 0;
        }
        try {
            $id = $this->readLE($ctx, $id_ptr_address, 4, 'V');
            if ($id === null || $id <= 0) {
                return 0;
            }
            $cache_base = $this->readLE($ctx, $this->tsrm_ls_cache_address, 8, 'P');
            if ($cache_base === null || $cache_base === 0) {
                return 0;
            }
            $globals = $this->readLE($ctx, $cache_base + ($id - 1) * 8, 8, 'P');
            return $globals ?? 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Read $size bytes from process memory at $address and unpack
     * with $format ('V' for u32 LE, 'P' for u64 LE). Returns null
     * on any failure — caller treats that as "skip this resolve".
     */
    private function readLE(
        CollectorContext $ctx,
        int $address,
        int $size,
        string $format,
    ): ?int {
        $bytes = (string)$ctx->dereferencer->deref(
            new \Reli\Lib\Process\Pointer\Pointer(
                \Reli\Lib\PhpInternals\Types\C\RawString::class,
                $address,
                $size,
            )
        );
        if (strlen($bytes) < $size) {
            return null;
        }
        $unpacked = @unpack($format, $bytes);
        if ($unpacked === false || !isset($unpacked[1]) || !is_int($unpacked[1])) {
            return null;
        }
        return $unpacked[1];
    }

    private function walkPharGlobals(
        int $globals_ptr,
        int $parent_id,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        $globals_pointer = new Pointer(
            PharGlobals::class,
            $globals_ptr,
            $ctx->zend_type_reader->sizeOf('phar_globals_truncated'),
        );
        try {
            $globals = $ctx->dereferencer->deref($globals_pointer);
        } catch (\Throwable) {
            return;
        }
        // phar_persist_map / phar_fname_map / phar_alias_map hold
        // phar_archive_data* values — walk the table and then follow
        // each archive into its own per-archive HashTables. mime_types
        // is keyed by file extension with string values, so the
        // archive-follow step doesn't apply.
        try {
            $persist = $globals->phar_persist_map;
            $this->walkGlobalsTable($persist, 'phar_persist_map', $parent_id, $ctx, $queue);
            $this->walkPharArchives($persist, $parent_id, $ctx, $queue);
        } catch (\Throwable) {
        }
        try {
            $fname = $globals->phar_fname_map;
            $this->walkGlobalsTable($fname, 'phar_fname_map', $parent_id, $ctx, $queue);
            $this->walkPharArchives($fname, $parent_id, $ctx, $queue);
        } catch (\Throwable) {
        }
        try {
            $alias = $globals->phar_alias_map;
            $this->walkGlobalsTable($alias, 'phar_alias_map', $parent_id, $ctx, $queue);
            $this->walkPharArchives($alias, $parent_id, $ctx, $queue);
        } catch (\Throwable) {
        }
        // mime_types is initialised persistent in phar MINIT and
        // (depending on RINIT state) can show up as a half-initialised
        // HashTable whose type_info bit fires IS_ARRAY but whose
        // nTableSize / arData are bogus. The looksLikeWalkableHashTable
        // guard inside walkGlobalsTable catches that case and skips
        // cleanly — without it we OOM'd reli at 14 GB feeding the
        // garbage size to processZendArray.
        try {
            $mime = $globals->mime_types;
            $this->walkGlobalsTable($mime, 'mime_types', $parent_id, $ctx, $queue);
        } catch (\Throwable) {
        }
        // Per-request transient char* fields in phar's globals
        // struct. Each tells us "X bytes of zend_mm-allocated buffer
        // pointed to by globals->FOO" — emit a MallocBufferMemoryLocation
        // so the bytes count toward the analyzed total. cache_list
        // has no paired _len; phar terminates it with a NUL so we
        // bound the read at a sane cap (CACHE_LIST_MAX_LEN).
        $globals_buf_ctx = new StandardModuleContext();
        $this->buildAndAttach($globals->cwd_address, $globals->cwd_len, 'phar.cwd', $ctx, $globals_buf_ctx);
        $this->buildAndAttach(
            $globals->openssl_privatekey_address,
            $globals->openssl_privatekey_len,
            'phar.openssl_privatekey',
            $ctx,
            $globals_buf_ctx,
        );
        $this->buildAndAttach(
            $globals->last_phar_name_address,
            $globals->last_phar_name_len,
            'phar.last_phar_name',
            $ctx,
            $globals_buf_ctx,
        );
        $this->buildAndAttach(
            $globals->last_alias_address,
            $globals->last_alias_len,
            'phar.last_alias',
            $ctx,
            $globals_buf_ctx,
        );
        // cache_list has no _len member — phar treats it as a
        // colon-separated NUL-terminated string. Cap the read so a
        // bogus pointer can't blow up the size sum.
        $this->buildAndAttach(
            $globals->cache_list_address,
            self::CACHE_LIST_MAX_LEN,
            'phar.cache_list',
            $ctx,
            $globals_buf_ctx,
        );
        // Emit only if we collected at least one buffer — empty
        // contexts add noise to the tree.
        if (iterator_to_array($globals_buf_ctx->getLocations()) !== []) {
            $ctx->emitNode($globals_buf_ctx, $parent_id >= 0 ? $parent_id : null, 'phar.buffers');
        }
    }

    private function buildAndAttach(
        int $address,
        int $len,
        string $tag,
        CollectorContext $ctx,
        StandardModuleContext $host,
    ): void {
        $loc = $this->buildMallocBuffer($address, $len, $tag, $ctx);
        if ($loc !== null) {
            $host->addLocation($loc);
        }
    }

    /** Cap for phar's cache_list NUL-terminated buffer when we
     *  don't have a length pair to bound the LocationRow size. */
    private const CACHE_LIST_MAX_LEN = 4096;

    /**
     * Build a MallocBufferMemoryLocation for $address/$len under the
     * given $tag. Returns the location (already added to
     * memory_locations) so the caller can attach it to a host
     * Context — the binary sink only writes LocationRows for
     * Context.getLocations() output, so a location not attached
     * anywhere is silently dropped on serialise.
     */
    private function buildMallocBuffer(
        int $address,
        int $len,
        string $tag,
        CollectorContext $ctx,
    ): ?MallocBufferMemoryLocation {
        if ($address === 0 || $len <= 0 || $len > self::CACHE_LIST_MAX_LEN) {
            return null;
        }
        if ($ctx->memory_locations->has($address)) {
            return null;
        }
        $location = new MallocBufferMemoryLocation($address, $len, $tag);
        $ctx->memory_locations->add($location);
        return $location;
    }

    private function walkGlobalsTable(
        ZendArray $ht,
        string $label,
        int $parent_id,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        try {
            $addr = $ht->getPointer()->address;
            if (isset($ctx->address_map[$addr])) {
                return;
            }
            if (!$this->looksLikeWalkableHashTable($ht)) {
                return;
            }
            EmitArrayJob::processZendArray(
                $ht,
                $parent_id >= 0 ? $parent_id : null,
                'phar.' . $label,
                EdgeStrength::Strong,
                $ctx,
                $queue,
            );
        } catch (\Throwable) {
            // uninitialised HashTable (e.g. mime_types before RINIT)
            // — silently skip.
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
                // Dedup: phar_persist_map / phar_fname_map /
                // phar_alias_map all hold the same archive pointer,
                // so this loop fires once per top-level table for
                // each archive. Record on address_map after the
                // first walk to skip the duplicates.
                if (isset($ctx->address_map[$archive_addr])) {
                    continue;
                }
                $ctx->address_map[$archive_addr] = $parent_id >= 0 ? $parent_id : 0;

                // Each inline HashTable inside phar_archive_data —
                // walking surfaces the per-archive zend_strings the
                // standard root walk can't reach.
                $this->walkArchiveTable($archive->manifest, 'manifest', $archive_addr, $parent_id, $ctx, $queue);
                $this->walkArchiveTable($archive->virtual_dirs, 'virtual_dirs', $archive_addr, $parent_id, $ctx, $queue);
                $this->walkArchiveTable($archive->mounted_dirs, 'mounted_dirs', $archive_addr, $parent_id, $ctx, $queue);
                // Per-archive "buffer" context holds the LocationRows
                // for the .phar file streams (fp/ufp), the char*
                // identifier/signature buffers, and the per-entry
                // serialized-metadata strings — the binary sink only
                // serialises a LocationRow when it's reachable via
                // some emitted Context.getLocations(), so we
                // accumulate everything here and emit at the end.
                $archive_buf_ctx = new StandardModuleContext();
                $on_loc = static function (\Reli\Lib\Process\MemoryLocation $loc) use ($archive_buf_ctx): void {
                    $archive_buf_ctx->addLocation($loc);
                };
                // The .phar file's underlying php_stream (typically
                // STDIO) plus the decompressed-content cache stream
                // (MEMORY/TEMP) — for tools shipped as a phar this
                // is where the bulk of the file's bytes land.
                $this->walkArchiveStream($archive->fp_address, $ctx, $on_loc);
                $this->walkArchiveStream($archive->ufp_address, $ctx, $on_loc);
                // Per-archive char* buffers — fname/ext/alias are
                // the archive identifiers (small but counted), and
                // signature is the phar's cryptographic signature
                // (can be a few KB for OpenSSL).
                $this->buildAndAttach($archive->fname->address, $archive->fname_len, 'phar.archive.fname', $ctx, $archive_buf_ctx);
                $this->buildAndAttach($archive->ext_address, $archive->ext_len, 'phar.archive.ext', $ctx, $archive_buf_ctx);
                $this->buildAndAttach($archive->alias_address, $archive->alias_len, 'phar.archive.alias', $ctx, $archive_buf_ctx);
                $this->buildAndAttach(
                    $archive->signature_address,
                    $archive->sig_len,
                    'phar.archive.signature',
                    $ctx,
                    $archive_buf_ctx,
                );
                // Per-entry metadata serialization: phar caches the
                // serialized form of each entry's metadata as a
                // zend_string. Walk every manifest bucket value
                // (phar_entry_info*), deref the entry struct, and
                // attach its metadata_str to the per-archive buffer
                // context when set.
                $this->walkArchiveEntryMetadata($archive->manifest, $ctx, $archive_buf_ctx);
                if (iterator_to_array($archive_buf_ctx->getLocations()) !== []) {
                    $ctx->emitNode(
                        $archive_buf_ctx,
                        $parent_id >= 0 ? $parent_id : null,
                        sprintf('archive[0x%x]', $archive_addr),
                    );
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /**
     * For each IS_PTR bucket value in the manifest, deref as
     * PharEntryInfo and emit a ZendStringMemoryLocation for the
     * serialized metadata (when set). Per-entry filename / tmp /
     * link char* are typically persistent (libc heap) so we don't
     * walk them; metadata_str on a non-persistent phar lives in
     * zend_mm and is what we're after here.
     */
    private function walkArchiveEntryMetadata(
        ZendArray $manifest,
        CollectorContext $ctx,
        StandardModuleContext $archive_buf_ctx,
    ): void {
        try {
            $iterator = $manifest->getItemIterator($ctx->dereferencer);
        } catch (\Throwable) {
            return;
        }
        $entry_size = $ctx->zend_type_reader->sizeOf('phar_entry_info_truncated');
        foreach ($iterator as $zval) {
            try {
                if ($zval->getType() !== 'IS_PTR') {
                    continue;
                }
                $entry_addr = $zval->value->lval;
                if ($entry_addr === 0) {
                    continue;
                }
                $entry = $ctx->dereferencer->deref(new Pointer(
                    PharEntryInfo::class,
                    $entry_addr,
                    $entry_size,
                ));
                $str_addr = $entry->metadata_str_address;
                if ($str_addr === 0) {
                    continue;
                }
                if ($ctx->memory_locations->has($str_addr)) {
                    continue;
                }
                $str = $ctx->dereferencer->deref(new Pointer(
                    \Reli\Lib\PhpInternals\Types\Zend\ZendString::class,
                    $str_addr,
                    $ctx->zend_type_reader->sizeOf('zend_string'),
                ));
                // Same MAX_PLAUSIBLE_STREAM_STRING_LEN heuristic as
                // EmitResourceJob — bogus `len` reads above this
                // are recycled-slot garbage, skip.
                if ($str->len < 0 || $str->len > PhpStreamWalker::MAX_PLAUSIBLE_STREAM_STRING_LEN) {
                    continue;
                }
                $loc = \Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation::fromZendString(
                    $str,
                    $ctx->dereferencer,
                );
                $ctx->memory_locations->add($loc);
                $archive_buf_ctx->addLocation($loc);
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /**
     * @param \Closure(\Reli\Lib\Process\MemoryLocation): void $on_location
     *     Callback invoked for every MemoryLocation the stream walker
     *     emits — caller attaches each to the archive's per-buffer
     *     host Context so the LocationRow makes it to the binary
     *     sink.
     */
    private function walkArchiveStream(
        int $stream_addr,
        CollectorContext $ctx,
        \Closure $on_location,
    ): void {
        if ($stream_addr === 0) {
            return;
        }
        if (isset($ctx->address_map[$stream_addr])) {
            return;
        }
        $walker = new PhpStreamWalker($ctx, $on_location);
        $stream = $walker->readStream($stream_addr);
        if ($stream === null) {
            return;
        }
        $walker->walkStreamData($stream);
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
            if (!$this->looksLikeWalkableHashTable($ht)) {
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
