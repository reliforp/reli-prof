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

namespace Reli\Inspector\Settings\MemoryDumpSettings;

use PhpCast\Cast;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

final class MemoryDumpSettingsFromConsoleInput
{
    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'output file path for the memory dump',
        );
        $command->addOption(
            'stop-process',
            null,
            InputOption::VALUE_NEGATABLE,
            'stop the process while dumping (default: on)',
            true,
        );
        $command->addOption(
            'include-binary',
            null,
            InputOption::VALUE_NONE,
            'include read-only binary segments in dump (self-contained, no --dependency-root needed at analyze time)',
        );
        $command->addOption(
            'exclude-heap',
            null,
            InputOption::VALUE_NONE,
            'exclude the glibc [heap] and anonymous writable mmap regions'
                . ' from the dump. Useful for lightweight recurring dumps'
                . ' (e.g. i:watch) when the target process has a large RSS'
                . ' from C extensions (libxml2, sqlite3, ImageMagick, etc.)'
                . ' but you only need to analyze PHP-managed memory.'
                . ' Typically saves 50-99% of dump size in such cases.',
        );
    }

    public function createSettings(InputInterface $input): MemoryDumpSettings
    {
        $output_path = Cast::toString($input->getOption('output'));
        if ($output_path === '') {
            throw new \RuntimeException('--output (-o) is required');
        }
        $stop_process = Cast::toBool($input->getOption('stop-process'));
        $include_binary = Cast::toBool($input->getOption('include-binary'));
        $exclude_heap = Cast::toBool($input->getOption('exclude-heap'));

        return new MemoryDumpSettings(
            $output_path,
            $stop_process,
            $include_binary,
            $exclude_heap,
        );
    }
}
