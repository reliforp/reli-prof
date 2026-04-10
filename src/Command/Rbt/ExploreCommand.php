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

namespace Reli\Command\Rbt;

use Reli\Command\Rbt\Explore\ExploreTui;
use Reli\Command\Rbt\Explore\Keymap;
use Reli\Command\Rbt\Explore\Terminal;
use Reli\Command\Rbt\Explore\TraceModel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interactive TUI for browsing a binary trace (.rbt).
 *
 * Loads the entire trace into memory once, then lets the user navigate
 * self/total/callers/callees views with arrow keys (or vim hjkl) and
 * drill into a frame's call sites without re-running anything. See
 * `--help` and the in-app `?` overlay for keybindings.
 */
final class ExploreCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('rbt:explore')
            ->setDescription('Interactive TUI explorer for binary traces (.rbt)')
            ->addArgument(
                'trace',
                InputArgument::REQUIRED,
                'path to a .rbt file (gzip auto-detected)',
            )
            ->addOption(
                'keymap',
                null,
                InputOption::VALUE_REQUIRED,
                'path to a JSON keymap file overriding the defaults',
            )
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $path */
        $path = $input->getArgument('trace');
        if (!is_file($path)) {
            $output->writeln("<error>Trace file not found: {$path}</error>");
            return 1;
        }

        /** @var string|null $keymap_path */
        $keymap_path = $input->getOption('keymap');
        $keymap = $keymap_path !== null
            ? Keymap::fromJsonFile($keymap_path)
            : Keymap::default();

        $output->writeln("<info>loading {$path} ...</info>");
        $model = TraceModel::load($path);
        $output->writeln(sprintf(
            '<info>loaded %s samples (%.1fs sampled wall, %d-us period)</info>',
            number_format($model->sampleCount()),
            $model->durationSeconds(),
            $model->sampling_period_us,
        ));

        $term = new Terminal();
        $tui = new ExploreTui($model, $term, $keymap);
        $tui->run();

        return 0;
    }
}
