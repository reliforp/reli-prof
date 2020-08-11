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

namespace PhpProfiler\Inspector\Settings\GetTraceSettings;

use PhpProfiler\Inspector\Settings\InspectorSettingsException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class GetTraceSettingsFromConsoleInput
{
    /**
     * @codeCoverageIgnore
     */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'depth',
                'd',
                InputOption::VALUE_OPTIONAL,
                'max depth'
            )
        ;
    }

    /**
     * @param InputInterface $input
     * @return GetTraceSettings
     * @throws InspectorSettingsException
     */
    public function createSettings(InputInterface $input): GetTraceSettings
    {
        $depth = $input->getOption('depth');
        if (is_null($depth)) {
            $depth = PHP_INT_MAX;
        }
        $depth = filter_var($depth, FILTER_VALIDATE_INT);
        if ($depth === false) {
            throw GetTraceSettingsException::create(GetTraceSettingsException::DEPTH_IS_NOT_INTEGER);
        }
        return new GetTraceSettings($depth);
    }
}
