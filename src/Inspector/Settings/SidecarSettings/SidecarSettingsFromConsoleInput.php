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

namespace Reli\Inspector\Settings\SidecarSettings;

use PhpCast\NullableCast;
use Reli\Inspector\Watch\HeapStats;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class SidecarSettingsFromConsoleInput
{
    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'socket',
                's',
                InputOption::VALUE_REQUIRED,
                'Unix domain socket path',
                SidecarSettings::DEFAULT_SOCKET_PATH,
            )
            ->addOption(
                'output-dir',
                'o',
                InputOption::VALUE_REQUIRED,
                'directory for dump output files',
                '.',
            )
            ->addOption(
                'disk-usage-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'max total disk usage for dumps (e.g., 1G, 512M)',
                '1G',
            )
            ->addOption(
                'include-binary',
                null,
                InputOption::VALUE_NONE,
                'include read-only binary segments in dumps',
            )
            ->addOption(
                'memory-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'set PHP memory_limit for the sidecar process (e.g., 2G)',
            )
        ;
    }

    public function createSettings(InputInterface $input): SidecarSettings
    {
        $disk_limit_str = NullableCast::toString($input->getOption('disk-usage-limit'));
        $disk_limit = $disk_limit_str !== null
            ? HeapStats::parseSize($disk_limit_str)
            : 1073741824;

        return new SidecarSettings(
            socket_path: (string)$input->getOption('socket'),
            output_dir: (string)$input->getOption('output-dir'),
            disk_usage_limit_bytes: $disk_limit,
            include_binary: (bool)$input->getOption('include-binary'),
            memory_limit: NullableCast::toString($input->getOption('memory-limit')),
        );
    }
}
