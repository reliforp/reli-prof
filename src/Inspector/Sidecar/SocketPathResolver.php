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

namespace Reli\Inspector\Sidecar;

use RuntimeException;

/**
 * Resolves the default path for the sidecar Unix domain socket and validates
 * that the chosen location has sane per-user permissions before binding.
 *
 * Resolution rules for {@see self::resolveDefault()}:
 *   - `$XDG_RUNTIME_DIR/reli/sidecar.sock` when `$XDG_RUNTIME_DIR` is set
 *     (systemd exposes `/run/user/$UID` with mode 0700 there).
 *   - Otherwise, throw with an instructive message. No `/tmp` fallback:
 *     `/tmp` is world-writable and a concurrent user could pre-create
 *     the socket path to intercept the sidecar IPC, which has no
 *     on-the-wire authentication.
 *
 * Validation (see {@see self::assertParentSafe()}) strictly rejects a
 * parent directory that is a symlink, not owned by the current uid, or
 * not mode 0700. The validator does not inspect the ancestor chain —
 * relying on the underlying filesystem's per-user permission model for
 * path components above the parent is sufficient for typical systemd
 * runtime dirs and conventional home directories.
 */
final class SocketPathResolver
{
    public const DIR_NAME = 'reli';
    public const FILE_NAME = 'sidecar.sock';

    public static function resolveDefault(): string
    {
        $xdgRuntimeDir = getenv('XDG_RUNTIME_DIR');
        if (is_string($xdgRuntimeDir) && $xdgRuntimeDir !== '' && is_dir($xdgRuntimeDir)) {
            return rtrim($xdgRuntimeDir, '/')
                . '/' . self::DIR_NAME
                . '/' . self::FILE_NAME;
        }

        throw new RuntimeException(
            'Cannot resolve default sidecar socket path: XDG_RUNTIME_DIR is not set or not a directory.'
            . ' Pass --socket explicitly (or set RELI_SIDECAR_SOCKET) to a user-owned 0700 parent dir.'
        );
    }

    /**
     * Ensures the parent directory of $socketPath exists with mode 0700 and
     * is owned by the current uid. Creates it (mode 0700) if missing.
     *
     * Does NOT traverse symlinks on ancestor path components — see class
     * docblock for rationale.
     */
    public static function assertParentSafe(string $socketPath): void
    {
        $parent = dirname($socketPath);

        if (!is_dir($parent)) {
            if (!@mkdir($parent, 0o700, true) && !is_dir($parent)) {
                throw new RuntimeException(
                    "Failed to create sidecar socket parent directory: {$parent}"
                );
            }
        }

        if (is_link($parent)) {
            throw new RuntimeException(
                "Sidecar socket parent directory is a symlink (refusing for safety): {$parent}"
            );
        }

        $stat = @stat($parent);
        if ($stat === false) {
            throw new RuntimeException("Cannot stat sidecar socket parent directory: {$parent}");
        }

        $currentUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($currentUid !== null && $stat['uid'] !== $currentUid) {
            throw new RuntimeException(sprintf(
                'Sidecar socket parent directory %s is owned by uid %d, expected %d. '
                . 'chown the directory or pick a user-owned path via --socket.',
                $parent,
                $stat['uid'],
                $currentUid,
            ));
        }

        $mode = $stat['mode'] & 0o777;
        if ($mode !== 0o700) {
            throw new RuntimeException(sprintf(
                'Sidecar socket parent directory %s has mode 0%o, expected 0700. '
                . "Run: chmod 0700 '%s'",
                $parent,
                $mode,
                $parent,
            ));
        }
    }
}
