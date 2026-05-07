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

namespace Reli\Inspector\Settings\PhpSpySettings;

use PhpCast\NullableCast;
use Reli\Inspector\Settings\OutputSettings\OutputSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Output options for `phpspy:trace` and `phpspy:daemon`.
 *
 * The CLI flag names mirror inspector:daemon
 * (`--output-format`, `--rbt-timestamps`, `--rbt-compress`, `-o`)
 * so users learn one vocabulary across the daemon-style commands.
 *
 * The accepted `--output-format` values differ by command and are
 * validated by the caller:
 *   - `phpspy:trace`  → `phpspy` (default, passthrough), `rbt`
 *   - `phpspy:daemon` → `phpspy` (default, muxed passthrough),
 *                       `rbt` (per-worker file in the resolved dir),
 *                       `rbt-bundled` (single PID_SAMPLE-tagged file)
 */
final class PhpSpyOutputSettingsFromConsoleInput
{
    /** @codeCoverageIgnore */
    public function setOptions(Command $command): void
    {
        $command
            ->addOption(
                'output-format',
                'f',
                InputOption::VALUE_REQUIRED,
                'output format. phpspy:trace accepts phpspy|rbt;'
                . ' phpspy:daemon accepts phpspy|rbt|rbt-bundled.',
                'phpspy',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'output path. For per-worker rbt mode this is a directory'
                . ' (default: $XDG_STATE_HOME/reli/daemon-traces/{session}).'
                . ' For phpspy / rbt-bundled formats this is a single file'
                . ' (default: stdout).'
            )
            ->addOption(
                'rbt-timestamps',
                null,
                InputOption::VALUE_REQUIRED,
                'timestamp mode for rbt formats: none (compact, default) or delta',
                'none',
            )
            ->addOption(
                'rbt-compress',
                null,
                InputOption::VALUE_NONE,
                'gzip-compress rbt output',
            )
        ;
    }

    public function createSettings(InputInterface $input): OutputSettings
    {
        $format = NullableCast::toString($input->getOption('output-format')) ?? 'phpspy';
        $output_path = NullableCast::toString($input->getOption('output'));
        $rbt_timestamps = NullableCast::toString($input->getOption('rbt-timestamps')) ?? 'none';
        $rbt_compress = (bool) $input->getOption('rbt-compress');

        return new OutputSettings(
            $format,
            $output_path,
            $rbt_timestamps,
            $rbt_compress,
        );
    }
}
