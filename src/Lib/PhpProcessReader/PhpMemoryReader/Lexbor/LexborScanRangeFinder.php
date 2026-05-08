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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Lexbor;

use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpTsrmLsCacheFinder;
use Reli\Lib\Process\MemoryMap\ProcessMemoryMap;
use Reli\Lib\Process\ProcessSpecifier;

/**
 * Compute the address ranges {@see LexborStateScanner} should walk on
 * a given target.
 *
 * On NTS builds ext/uri's `ZEND_TLS lexbor_idna` etc. expand to plain
 * `static`, so the variables live in the main PHP binary's `.bss`.
 * The Linux loader maps the binary's RW LOAD segment file-backed up
 * to the file-backed end and uses an anonymous mapping immediately
 * after for any BSS pages that don't fit. Both pieces are returned.
 *
 * On ZTS builds the variables are `__thread` in the binary's PT_TLS
 * image; the live thread's TLS block address comes from the same
 * resolver TSRM-cache lookup uses, and is appended as an additional
 * range so a single scanner pass covers both build flavours.
 *
 * Pure / side-effect-free: the only effects are the `process_memory_map`
 * lookup (already done by the caller) and an opportunistic TLS-block
 * resolution that swallows its own exceptions. Easy to unit-test by
 * handing a fake `ProcessMemoryMap` and asserting the returned ranges.
 */
final class LexborScanRangeFinder
{
    /**
     * @param TargetPhpSettings<value-of<ZendTypeReader::ALL_SUPPORTED_VERSIONS>> $target_php_settings
     * @return list<array{int, int}>
     */
    public static function find(
        ProcessSpecifier $process_specifier,
        TargetPhpSettings $target_php_settings,
        ProcessMemoryMap $process_memory_map,
        ?PhpTsrmLsCacheFinder $tsrm_ls_cache_finder = null,
    ): array {
        $ranges = [];

        $binary_areas = $process_memory_map->findByNameRegex(
            $target_php_settings->php_regex,
        );
        if ($binary_areas !== []) {
            // Find the writable file-backed mapping (`.data` + the
            // file-backed start of `.bss`).
            $rw_lo = null;
            $rw_hi = null;
            foreach ($binary_areas as $area) {
                if (
                    $area->attribute->read
                    && $area->attribute->write
                    && !$area->attribute->execute
                ) {
                    $rw_lo = (int)hexdec($area->begin);
                    $rw_hi = (int)hexdec($area->end);
                    break;
                }
            }
            if ($rw_lo !== null && $rw_hi !== null && $rw_hi > $rw_lo) {
                $ranges[] = [$rw_lo, $rw_hi - $rw_lo];
                // The loader fills the BSS tail with an anonymous
                // mapping that starts at the file-backed end. Detect
                // it by walking the full memory map for an `rw-p` anon
                // area whose `begin` equals our `rw_hi`.
                foreach ($process_memory_map->findByNameRegex('.*') as $area) {
                    if (
                        $area->name !== ''
                        || !$area->attribute->read
                        || !$area->attribute->write
                        || $area->attribute->execute
                    ) {
                        continue;
                    }
                    $lo = (int)hexdec($area->begin);
                    $hi = (int)hexdec($area->end);
                    if ($lo === $rw_hi && $hi > $lo) {
                        $ranges[] = [$lo, $hi - $lo];
                        break;
                    }
                }
            }
        }

        // ZTS path: also scan the live thread's PT_TLS image when it
        // exists (resolveTlsBlock returns null on NTS builds). On NTS
        // this contributes nothing; on ZTS it covers the case where
        // ZEND_TLS expands to `__thread`.
        if ($tsrm_ls_cache_finder !== null) {
            try {
                $tls_block = $tsrm_ls_cache_finder->resolveTlsBlock(
                    $process_specifier,
                    $target_php_settings,
                );
                if ($tls_block !== null) {
                    [$tls_address, $tls_size,] = $tls_block;
                    if ($tls_size > 0) {
                        $ranges[] = [$tls_address, $tls_size];
                    }
                }
            } catch (\Throwable) {
                // Best-effort: a missing TLS block is fine on NTS.
            }
        }

        return $ranges;
    }
}
