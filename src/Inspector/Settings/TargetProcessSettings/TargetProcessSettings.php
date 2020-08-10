<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Settings\TargetProcessSettings;

use PhpProfiler\Inspector\Settings\InspectorSettingsException;
use Symfony\Component\Console\Input\InputInterface;

final class TargetProcessSettings
{
    public int $pid;

    /**
     * GetTraceSettings constructor.
     * @param int $pid
     */
    public function __construct(int $pid)
    {
        $this->pid = $pid;
    }

    /**
     * @param InputInterface $input
     * @return self
     * @throws InspectorSettingsException
     */
    public static function fromConsoleInput(InputInterface $input): self
    {
        $pid = $input->getOption('pid');
        if (is_null($pid)) {
            throw TargetProcessInspectorSettingsException::create(
                TargetProcessInspectorSettingsException::PID_NOT_SPECIFIED
            );
        }
        $pid = filter_var($pid, FILTER_VALIDATE_INT);
        if ($pid === false) {
            throw TargetProcessInspectorSettingsException::create(
                TargetProcessInspectorSettingsException::PID_NOT_SPECIFIED
            );
        }

        return new self($pid);
    }
}
