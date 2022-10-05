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

namespace Reli\Inspector\TargetProcess;

use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettings;
use Reli\Lib\Process\Exec\TraceeExecutor;
use Reli\Lib\Process\ProcessSpecifier;

class TargetProcessResolver
{
    public function __construct(
        private TraceeExecutor $tracee_executor,
    ) {
    }

    public function resolve(
        TargetProcessSettings $target_process_settings
    ): ProcessSpecifier {
        if (!is_null($target_process_settings->pid)) {
            return new ProcessSpecifier($target_process_settings->pid);
        }
        assert(!is_null($target_process_settings->command));
        $pid = $this->tracee_executor->execute(
            $target_process_settings->command,
            $target_process_settings->arguments
        );
        return new ProcessSpecifier($pid);
    }
}
