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

namespace PhpProfiler\Lib\Console;

use function exec;

final class EchoBackCanceller
{
    private ?string $stty_settings;

    public function __construct()
    {
        /** @psalm-suppress ForbiddenCode */
        $this->stty_settings = shell_exec('stty -g');
        exec('stty -icanon -echo');
    }

    public function __destruct()
    {
        if (isset($this->stty_settings)) {
            exec('stty ' . $this->stty_settings);
        } else {
            exec('stty icanon echo');
        }
    }
}
