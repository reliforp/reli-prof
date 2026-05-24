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

use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendModuleEntry;
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
        // Today we only walk phar. Other modules can be added once
        // they show up as gap contributors in real workloads.
        $targets = ['Phar'];

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
        $zval = $bucket->val;
        if ($zval->getType() !== 'IS_PTR') {
            return;
        }
        $entry_pointer = new Pointer(
            ZendModuleEntry::class,
            $zval->value->lval,
            $ctx->zend_type_reader->sizeOf('zend_module_entry'),
        );
        $entry = $ctx->dereferencer->deref($entry_pointer);
        if ($entry->zts) {
            // ZTS path stores a `ts_rsrc_id*` in globals_ptr and
            // requires TSRM offset resolution. Defer until we see a
            // ZTS gap to motivate it.
            return;
        }
        $globals_ptr = $entry->globals_ptr;
        $globals_size = $entry->globals_size;
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
            $refcount = $ht->gc->refcount;
            if ($refcount > 0x0FFF_FFFF) {
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
        }
    }
}
