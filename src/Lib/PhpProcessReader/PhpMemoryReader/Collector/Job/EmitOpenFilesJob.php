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

use Reli\Lib\PhpInternals\Types\Zend\ZendFileHandle;
use Reli\Lib\PhpInternals\Types\Zend\ZendLlist;
use Reli\Lib\PhpInternals\Types\Zend\ZendLlistElement;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\MallocBufferMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\StandardModuleContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Attribute the source buffers of currently-open compiled files.
 *
 * `zend_stream_fixup()` reads each script Zend compiles into a
 * `zend_file_handle.buf` heap allocation and (for the primary script)
 * holds it for the whole process lifetime — it is freed only when
 * `do_cli`/the SAPI destroys the handle at shutdown. For a tool shipped
 * as a phar this single allocation is the entire phar image (multi-MB),
 * which lands in a ZendMM *huge* allocation and otherwise shows up as
 * unattributed heap: the buffer is not reachable through the PHP object
 * graph (it hangs off the C-level file handle, not a zval) nor through
 * the phar extension structs (`fp`/`ufp`/`cached_fp` don't point at it).
 *
 * The clean root is `CG(open_files)` — a `zend_llist` into which every
 * open file handle is copied (the llist stores element *copies*). We
 * walk that list and emit a MallocBuffer location for each handle's
 * `buf` (sized by `len`). At an idle capture only the primary script
 * remains in the list; mid-compile captures additionally see the entry
 * being compiled. All offsets come from the per-version FFI layout, so
 * no offset is hard-coded here.
 */
final class EmitOpenFilesJob implements CollectorJob
{
    /** Defensive ceiling on the llist walk — CG(open_files) holds a
     *  handful of entries in practice; anything past this means we are
     *  chasing a corrupted / mis-aligned list. */
    private const MAX_OPEN_FILES = 4096;

    /** A file handle's source buffer is at most the script size. 1 GiB
     *  is comfortably above any real script/phar while rejecting a
     *  garbage `len` read off a half-initialised handle. */
    private const MAX_BUF_LEN = 0x4000_0000;

    public function __construct(
        private int $cg_address,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        if ($this->cg_address === 0) {
            return;
        }
        try {
            $this->walk($ctx);
        } catch (\Throwable $e) {
            \Reli\Lib\Log\Log::debug(
                'failed to walk CG(open_files)',
                ['exception' => $e->getMessage()],
            );
        }
    }

    private function walk(CollectorContext $ctx): void
    {
        $tr = $ctx->zend_type_reader;
        [$open_files_off] = $tr->getOffsetAndSizeOfMember('zend_compiler_globals', 'open_files');
        [$data_off]       = $tr->getOffsetAndSizeOfMember('zend_llist_element', 'data');
        $element_size     = $tr->sizeOf('zend_llist_element');
        $file_handle_size = $tr->sizeOf('zend_file_handle');
        $llist_size       = $tr->sizeOf('zend_llist');

        $llist = $ctx->dereferencer->deref(new Pointer(
            ZendLlist::class,
            $this->cg_address + $open_files_off,
            $llist_size,
        ));
        $cur = $llist->head;

        $host = new StandardModuleContext();
        $seen = [];
        $guard = 0;
        while ($cur !== 0 && $guard++ < self::MAX_OPEN_FILES) {
            if (isset($seen[$cur])) {
                break;
            }
            $seen[$cur] = true;

            try {
                $element = $ctx->dereferencer->deref(new Pointer(
                    ZendLlistElement::class,
                    $cur,
                    $element_size,
                ));
                $file_handle = $ctx->dereferencer->deref(new Pointer(
                    ZendFileHandle::class,
                    $cur + $data_off,
                    $file_handle_size,
                ));
            } catch (\Throwable) {
                break;
            }

            $buf = $file_handle->buf;
            $len = $file_handle->len;
            if (
                $buf !== 0
                && $len > 0 && $len <= self::MAX_BUF_LEN
                && !$ctx->memory_locations->has($buf)
            ) {
                $tag = match ($file_handle->primary_script) {
                    1 => 'script_source.primary',
                    0 => 'script_source.included',
                    default => 'script_source',
                };
                $location = new MallocBufferMemoryLocation($buf, $len, $tag);
                $ctx->memory_locations->add($location);
                $host->addLocation($location);
            }

            $cur = $element->next;
        }

        if (iterator_to_array($host->getLocations()) !== []) {
            $ctx->emitNode($host, null, 'open_files');
        }
    }
}
