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
 * Distinguishing "binary has no PT_TLS at all" (NTS) from "PT_TLS exists but
 * the scanned thread's slot was empty" (ZTS, idle worker) lets the caller
 * decide whether to persist a negative cache entry: only the former is a
 * binary-level fact.
 */
final readonly class TsrmLsCacheBruteForceResult
{
    public function __construct(
        public ?int $address,
        public bool $has_tls_segment,
    ) {
    }
}
