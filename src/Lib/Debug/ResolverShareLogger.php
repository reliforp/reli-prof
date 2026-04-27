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

namespace Reli\Lib\Debug;

/**
 * Debug-only env-gated logger + experiment switches for the ARM64
 * zend_mm-corrupted bisect investigation.
 *
 *   RELI_DEBUG_RESOLVER_SHARE=1
 *       Enable file logging of resolver-share / cache events to
 *       /tmp/reli-test/resolver-share-<pid>.log. The CI workflow
 *       uploads that path as an artifact on every run.
 *
 *   RELI_DEBUG_FORCE_PARSED_RESOLVER_SHARE=1
 *       Re-enable the broken parsed-resolver-share path that #676
 *       removed in favour of bytes-only sharing. With this flag set,
 *       Elf64LazyParseSymbolResolver caches the parsed
 *       Elf64SymbolResolver instance (deep object graph) per binary
 *       fingerprint, reproducing the ARM64 corruption.
 *
 * This file is intended to be removed once the root cause is understood
 * and a real fix lands.
 */
final class ResolverShareLogger
{
    private static ?bool $enabled = null;
    private static ?bool $force_parsed_share = null;
    private static ?string $log_path = null;

    public static function enabled(): bool
    {
        if (self::$enabled === null) {
            self::$enabled = (string)getenv('RELI_DEBUG_RESOLVER_SHARE') === '1';
        }
        return self::$enabled;
    }

    public static function forceParsedResolverShare(): bool
    {
        if (self::$force_parsed_share === null) {
            self::$force_parsed_share =
                (string)getenv('RELI_DEBUG_FORCE_PARSED_RESOLVER_SHARE') === '1';
        }
        return self::$force_parsed_share;
    }

    /**
     * Bypass the bytes-share cache when set. Combined with
     * forceParsedResolverShare(), this re-creates #674's broken state
     * exactly: every miss re-reads the 19 MB binary off disk, parses it,
     * then keeps only the parsed Elf64SymbolResolver alive in the cache
     * (the 19 MB string is dropped once parsing completes). Hypothesis:
     * the repeated 19 MB alloc/free pattern combined with the long-lived
     * deep object graph is what stresses zend_mm on ARM64.
     */
    public static function disableBytesShare(): bool
    {
        return (string)getenv('RELI_DEBUG_DISABLE_BYTES_SHARE') === '1';
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function log(string $tag, array $fields = []): void
    {
        if (!self::enabled()) {
            return;
        }
        $pid = getmypid();
        $line = sprintf(
            "[%s pid=%d mem=%d] %s %s\n",
            self::timestamp(),
            $pid === false ? -1 : $pid,
            memory_get_usage(true),
            $tag,
            self::formatFields($fields),
        );
        @file_put_contents(self::path(), $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Snapshot memory + GC state. Useful for correlating cache populate
     * events with PHP runtime book-keeping changes.
     */
    public static function snapshot(string $tag): void
    {
        if (!self::enabled()) {
            return;
        }
        $gc = gc_status();
        self::log($tag, [
            'memory' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'gc_runs' => $gc['runs'] ?? -1,
            'gc_collected' => $gc['collected'] ?? -1,
            'gc_threshold' => $gc['threshold'] ?? -1,
            'gc_roots' => $gc['roots'] ?? -1,
        ]);
    }

    private static function path(): string
    {
        if (self::$log_path === null) {
            $dir = is_dir('/tmp/reli-test') ? '/tmp/reli-test' : sys_get_temp_dir();
            $pid = getmypid();
            self::$log_path = sprintf('%s/resolver-share-%d.log', $dir, $pid === false ? -1 : $pid);
        }
        return self::$log_path;
    }

    private static function timestamp(): string
    {
        $t = microtime(true);
        $whole = (int)$t;
        $micros = (int)(($t - $whole) * 1_000_000.0);
        return date('H:i:s', $whole) . sprintf('.%06d', $micros);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function formatFields(array $fields): string
    {
        $parts = [];
        foreach ($fields as $k => $v) {
            $parts[] = $k . '=' . self::renderValue($v);
        }
        return implode(' ', $parts);
    }

    /**
     * @param mixed $v
     */
    private static function renderValue(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        if (is_string($v)) {
            return $v;
        }
        if (is_object($v)) {
            return get_class($v);
        }
        if (is_array($v)) {
            $encoded = json_encode($v);
            return $encoded === false ? '?' : $encoded;
        }
        return '?';
    }
}
