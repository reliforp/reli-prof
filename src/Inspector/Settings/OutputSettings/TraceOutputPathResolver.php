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

namespace Reli\Inspector\Settings\OutputSettings;

final class TraceOutputPathResolver
{
    /**
     * Resolve the output directory for per-worker rbt mode.
     *
     * If the user specified -o, use that path.
     * Otherwise, create a session directory under XDG_STATE_HOME.
     *
     * @return string Absolute path to the output directory (created if needed)
     */
    public static function resolveRbtOutputDir(?string $user_path): string
    {
        if ($user_path !== null) {
            if (!is_dir($user_path)) {
                mkdir($user_path, 0755, true);
            }
            return $user_path;
        }

        $state_home = getenv('XDG_STATE_HOME');
        if ($state_home === false || $state_home === '') {
            $home = getenv('HOME');
            if ($home === false || $home === '') {
                $home = '/tmp';
            }
            $state_home = $home . '/.local/state';
        }

        $pid = getmypid();
        $session_name = gmdate('Y-m-d\THis\Z') . '_' . ($pid !== false ? $pid : 0);
        $dir = $state_home . '/reli/daemon-traces/' . $session_name;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }
}
