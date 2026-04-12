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

use Reli\Lib\PhpInternals\Types\C\RawString;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\PhpInternals\Types\Zend\ZendString;

/**
 * Walk the opcache SHM interned string buffer (start→top) and emit
 * each string. This covers interned strings that are only reachable
 * via opcache's persistent_script cache and not from the runtime
 * function_table/class_table.
 *
 * The buffer is found at zend_accel_shared_globals (SHM+0x88),
 * field interned_strings (offset 160), which has start/top pointers.
 */
final class EmitShmInternedStringsJob implements CollectorJob
{
    public function __construct(
        private int $shm_base,
        private int $shm_end,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        // Probe zend_accel_shared_globals at SHM+0x88
        $asg_addr = $this->shm_base + 0x88;
        $is_offset = 160; // interned_strings field offset in zend_accel_shared_globals

        try {
            $is_ptr = new Pointer(
                RawString::class,
                $asg_addr + $is_offset,
                40,
            );
            $is_raw = (string)$ctx->dereferencer->deref($is_ptr);
        } catch (\Throwable) {
            return;
        }
        if (strlen($is_raw) < 40) {
            return;
        }

        /** @var array{1: int} */
        $start_u = unpack('P', $is_raw, 8);
        $start = $start_u[1];
        /** @var array{1: int} */
        $top_u = unpack('P', $is_raw, 16);
        $top = $top_u[1];

        if (
            $start < $this->shm_base || $start >= $this->shm_end
            || $top <= $start || $top > $this->shm_end
        ) {
            return;
        }

        $buf_size = $top - $start;
        $str_hdr_size = $ctx->zend_type_reader->sizeOf('zend_string');

        // Read the entire buffer
        try {
            $buf_ptr = new Pointer(
                RawString::class,
                $start,
                $buf_size,
            );
            $buf = (string)$ctx->dereferencer->deref($buf_ptr);
        } catch (\Throwable) {
            return;
        }
        if (strlen($buf) < $str_hdr_size) {
            return;
        }

        // Walk packed zend_string entries
        $off = 0;
        $skip_count = 0;
        while ($off + $str_hdr_size <= $buf_size) {
            /** @var array{1: int} */
            $rc_u = unpack('V', $buf, $off);
            $refcount = $rc_u[1];
            if ($refcount !== 2) { // GC_IMMUTABLE
                $off += 8;
                $skip_count++;
                if ($skip_count > 100) {
                    break;
                }
                continue;
            }
            $skip_count = 0;

            /** @var array{1: int} */
            $len_u = unpack('P', $buf, $off + 16);
            $len = $len_u[1];
            if ($len > $buf_size) {
                break;
            }

            $str_addr = $start + $off;
            $full_size = 24 + $len + 1;

            // Collect this string via the standard helper
            try {
                $str_pointer = new Pointer(
                    ZendString::class,
                    $str_addr,
                    $str_hdr_size,
                );
                CollectorHelpers::collectZendStringPointer($str_pointer, $ctx);
            } catch (\Throwable) {
            }

            $aligned = ($full_size + 7) & ~7;
            $off += $aligned;
        }
    }
}
