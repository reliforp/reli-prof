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
use Reli\Sidecar\Client\SocketPathResolver;
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
                'Unix domain socket path'
                . ' (default: $XDG_RUNTIME_DIR/reli/sidecar.sock)',
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
            ->addOption(
                'tag',
                't',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'session-level tag applied to every snapshot (key=value)',
            )
        ;
    }

    public function createSettings(InputInterface $input): SidecarSettings
    {
        $disk_limit_str = NullableCast::toString($input->getOption('disk-usage-limit'));
        $disk_limit = $disk_limit_str !== null
            ? HeapStats::parseSize($disk_limit_str)
            : 1073741824;

        /** @var list<string> $tag_options */
        $tag_options = $input->getOption('tag');
        $tags = [];
        foreach ($tag_options as $tag) {
            $pos = strpos($tag, '=');
            if ($pos !== false) {
                $tags[substr($tag, 0, $pos)] = substr($tag, $pos + 1);
            }
        }

        $socket = NullableCast::toString($input->getOption('socket'));
        if ($socket === null || $socket === '') {
            $socket = SocketPathResolver::resolveDefault();
        }

        return new SidecarSettings(
            socket_path: $socket,
            output_dir: (string)$input->getOption('output-dir'),
            disk_usage_limit_bytes: $disk_limit,
            include_binary: (bool)$input->getOption('include-binary'),
            memory_limit: NullableCast::toString($input->getOption('memory-limit')),
            tags: $tags,
        );
    }
}
