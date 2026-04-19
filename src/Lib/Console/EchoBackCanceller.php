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

namespace Reli\Lib\Console;

use function exec;

final class EchoBackCanceller
{
    private ?string $stty_settings;
    private bool $enabled;

    public function __construct()
    {
        $this->enabled = \defined('STDIN') && \function_exists('posix_isatty') && \posix_isatty(\STDIN);
        if (!$this->enabled) {
            $this->stty_settings = null;
            return;
        }
        /** @psalm-suppress ForbiddenCode */
        $result = shell_exec('stty -g');
        $this->stty_settings = $result !== false ? $result : null;
        exec('stty -icanon -echo');
    }

    public function __destruct()
    {
        if (!$this->enabled) {
            return;
        }
        if (isset($this->stty_settings)) {
            exec('stty ' . $this->stty_settings);
        } else {
            exec('stty icanon echo');
        }
    }
}
