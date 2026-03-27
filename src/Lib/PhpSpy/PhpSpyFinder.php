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

namespace Reli\Lib\PhpSpy;

final class PhpSpyFinder
{
    public const DEFAULT_INSTALL_PATH = '/.reli/bin/phpspy';

    public function find(?string $explicit_path = null): string
    {
        // 1. Explicit path from --phpspy-path option
        if ($explicit_path !== null) {
            if (is_executable($explicit_path)) {
                return $explicit_path;
            }
            throw new PhpSpyNotFoundException();
        }

        // 2. PHPSPY_PATH env var
        $env_path = getenv('PHPSPY_PATH');
        if ($env_path !== false && $env_path !== '' && is_executable($env_path)) {
            return $env_path;
        }

        // 3. ~/.reli/bin/phpspy
        $home = getenv('HOME');
        if ($home !== false && $home !== '') {
            $reli_path = $home . self::DEFAULT_INSTALL_PATH;
            if (is_executable($reli_path)) {
                return $reli_path;
            }
        }

        // 4. which phpspy
        $which_result = exec('which phpspy 2>/dev/null', $output_lines, $result_code);
        if ($result_code === 0 && $which_result !== false && $which_result !== '') {
            $which_result = trim($which_result);
            if ($which_result !== '' && is_executable($which_result)) {
                return $which_result;
            }
        }

        throw new PhpSpyNotFoundException();
    }

    public static function getDefaultInstallDir(): string
    {
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            throw new \RuntimeException('HOME environment variable is not set');
        }
        return $home . '/.reli/bin';
    }

    public static function getDefaultInstallPath(): string
    {
        return self::getDefaultInstallDir() . '/phpspy';
    }
}
