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

namespace Reli\Lib\PhpProcessReader;

/**
 * Outcome of {@see PhpTsrmLsCacheFinder::findByBruteForcing()}.
 *
 * Three states matter to the caller:
 *
 *  - address !== null: TSRM_LS_CACHE was found, regardless of has_tls_segment.
 *  - address === null && has_tls_segment === true: PT_TLS exists in the ELF
 *    but no slot in the scanned thread's TLS block validated. This is a
 *    per-thread "the slot is still zero" situation (typical of an idle
 *    FrankenPHP worker) and must NOT be persisted as a binary-level negative.
 *  - address === null && has_tls_segment === false: confirmed no PT_TLS in
 *    the ELF -- the binary is NTS, and the negative cache entry is safe to
 *    persist.
 *  - address === null && has_tls_segment === null: the ELF could not be
 *    read or parsed enough to tell either way (for example the
 *    /proc/<pid>/root/... path race-disappeared, or the file_reader returned
 *    an empty buffer). The caller must NOT persist a negative cache entry,
 *    because doing so would lock in a guess as a binary-level fact.
 */
final readonly class TsrmLsCacheBruteForceResult
{
    public function __construct(
        public ?int $address,
        public ?bool $has_tls_segment,
    ) {
    }
}
