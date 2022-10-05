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

namespace PhpProfiler\Inspector\Settings\TargetProcessSettings;

use PhpCast\NullableCast;
use PhpProfiler\Inspector\Settings\InspectorSettingsException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function filter_var;
use function is_null;

use const FILTER_VALIDATE_INT;

final class TargetProcessSettingsFromConsoleInput
{
    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'pid',
                'p',
                InputOption::VALUE_REQUIRED,
                'process id'
            )
            ->addArgument(
                'cmd',
                InputArgument::OPTIONAL,
                'command to execute as a target: either pid (via -p/--pid) or cmd must be specified'
            )
            ->addArgument(
                'args',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'command line arguments for cmd',
            )
        ;
    }

    /**
     * @throws InspectorSettingsException
     */
    public function createSettings(InputInterface $input): TargetProcessSettings
    {
        $pid = NullableCast::toString($input->getOption('pid'));
        $command = NullableCast::toString($input->getArgument('cmd'));
        if (is_null($pid) and is_null($command)) {
            throw TargetProcessSettingsException::create(
                TargetProcessSettingsException::TARGET_NOT_SPECIFIED
            );
        }
        if (!is_null($pid)) {
            $pid = filter_var($pid, FILTER_VALIDATE_INT);
            if ($pid === false) {
                throw TargetProcessSettingsException::create(
                    TargetProcessSettingsException::TARGET_NOT_SPECIFIED
                );
            }
            return new TargetProcessSettings($pid);
        }
        /** @var list<string> $args */
        $args = $input->getArgument('args');
        return new TargetProcessSettings(null, $command, $args);
    }
}
