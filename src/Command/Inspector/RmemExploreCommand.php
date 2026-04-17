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

namespace Reli\Command\Inspector;

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\GraphSubstrate;
use Reli\Rbt\Explore\Keymap;
use Reli\Rbt\Explore\Terminal;
use Reli\Rmem\Explore\RmemExploreTui;
use Reli\Rmem\Explore\RmemModel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interactive TUI for browsing a .rmem memory snapshot.
 */
final class RmemExploreCommand extends Command
{
    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:rmem:explore')
            ->setDescription('Interactive TUI explorer for .rmem memory snapshots')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'path to a .rmem file',
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
        $file = (string) $input->getArgument('file');
        if (!is_file($file)) {
            $output->writeln("<error>File not found: {$file}</error>");
            return 1;
        }

        /** @var string|null $keymap_path */
        $keymap_path = $input->getOption('keymap');
        $keymap = $keymap_path !== null
            ? Keymap::fromJsonFile($keymap_path)
            : Keymap::default();

        $output->writeln("<info>loading {$file} ...</info>");
        $reader = BinaryReader::open($file);
        $output->writeln('<info>building substrate ...</info>');
        $substrate = GraphSubstrate::createFromBinary($reader, skipScc: true);
        $output->writeln('<info>building model ...</info>');
        $model = RmemModel::fromSubstrate($substrate, $reader);
        $output->writeln(sprintf(
            '<info>loaded %s edges, starting TUI</info>',
            number_format($model->edgeCount),
        ));
        $reader->clearCastCache();

        $term = new Terminal();
        $tui = new RmemExploreTui($model, $term, $keymap);
        $tui->run();

        return 0;
    }
}
