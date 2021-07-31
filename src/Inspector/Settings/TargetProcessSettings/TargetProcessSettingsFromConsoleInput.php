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

use PhpCast\NullableCast;
use PhpProfiler\Inspector\Settings\InspectorSettingsException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class TargetProcessSettingsFromConsoleInput
{
    /**
     * @codeCoverageIgnore
     */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'pid',
                'p',
                InputOption::VALUE_REQUIRED,
                'process id (required)'
            )
        ;
    }

    /**
     * @param InputInterface $input
     * @return TargetProcessSettings
     * @throws InspectorSettingsException
     */
    public function createSettings(InputInterface $input): TargetProcessSettings
    {
        $pid = NullableCast::toString($input->getOption('pid'));
        if (is_null($pid)) {
            throw TargetProcessSettingsException::create(
                TargetProcessSettingsException::PID_NOT_SPECIFIED
            );
        }
        $pid = filter_var($pid, FILTER_VALIDATE_INT);
        if ($pid === false) {
            throw TargetProcessSettingsException::create(
                TargetProcessSettingsException::PID_NOT_SPECIFIED
            );
        }

        return new TargetProcessSettings($pid);
    }
}
