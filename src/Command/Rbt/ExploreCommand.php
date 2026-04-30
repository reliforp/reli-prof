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

use Reli\Lib\String\PathMap;
use Reli\Rbt\Explore\ExploreTui;
use Reli\Rbt\Explore\Keymap;
use Reli\Rbt\Explore\Terminal;
use Reli\Rbt\Explore\TraceModel;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
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
final class ExploreCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('rbt:explore')
            ->setDescription('Interactive TUI explorer for binary traces (.rbt)')
            ->addArgument(
                'trace',
                InputArgument::OPTIONAL,
                'path to a .rbt file (gzip auto-detected)',
            )
            ->addOption(
                'keymap',
                null,
                InputOption::VALUE_REQUIRED,
                'path to a JSON keymap file overriding the defaults',
            )
            ->addOption(
                'diagnose',
                null,
                InputOption::VALUE_NONE,
                'run a terminal self-test (no trace required): enter raw mode'
                . ' and dump every received key sequence as hex until you press q.'
                . ' Use this if the TUI seems to ignore keys to verify whether'
                . ' bytes are reaching PHP at all',
            )
            ->addOption(
                'path-map',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'rewrite file paths in the trace for local navigation;'
                . ' format is "from=to" where "from" is a prefix recorded in'
                . ' the trace and "to" is the local replacement'
                . ' (e.g. --path-map /var/www/html=/home/you/project'
                . ' or --path-map /var/www/html=. for project-relative paths).'
                . ' May be specified multiple times; longest prefix wins',
            )
            ->addOption(
                'memory-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'set PHP memory_limit for analysis (e.g. 2G, 512M)',
            )
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $memory_limit */
        $memory_limit = $input->getOption('memory-limit');
        if (is_string($memory_limit) && $memory_limit !== '') {
            ini_set('memory_limit', $memory_limit);
        }
        if ((bool) $input->getOption('diagnose')) {
            return $this->runDiagnose($output);
        }

        /** @var string|null $path */
        $path = $input->getArgument('trace');
        if ($path === null || $path === '') {
            $output->writeln(
                '<error>Missing trace file argument'
                . ' (use --diagnose to run a terminal self-test instead).</error>'
            );
            return 1;
        }
        if (!is_file($path)) {
            $output->writeln("<error>Trace file not found: {$path}</error>");
            return 1;
        }

        /** @var string|null $keymap_path */
        $keymap_path = $input->getOption('keymap');
        $keymap = $keymap_path !== null
            ? Keymap::fromJsonFile($keymap_path)
            : Keymap::default();

        /** @var list<string> $path_map_raw */
        $path_map_raw = $input->getOption('path-map');
        try {
            $path_map = PathMap::parseOption($path_map_raw);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        $output->writeln("<info>loading {$path} ...</info>");
        $model = TraceModel::load($path, $path_map->toArray());
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

    /**
     * Terminal self-test: enter raw mode, print every captured key
     * sequence as a hex dump, exit on `q`. Bypasses the TUI loop so
     * you can verify in isolation whether the keyboard plumbing is
     * working — answers "is stty applied?", "is fgetc returning per
     * keystroke?", and "what bytes is my terminal actually sending?".
     */
    private function runDiagnose(OutputInterface $output): int
    {
        $output->writeln('<info>rbt:explore diagnose mode</info>');
        $output->writeln('Press keys to see what bytes reach PHP. Press <comment>q</comment> to quit.');
        $output->writeln('If nothing is reported when you press keys, the terminal is likely not in raw mode.');
        $output->writeln('');

        $term = new Terminal();
        try {
            $term->enter();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }

        try {
            // Use stdout (not the alt screen) for the diagnose dump so
            // the user can scroll the history afterwards. The Terminal
            // helper enters the alt screen on its own; we just leave it
            // enabled and write through it.
            while (true) {
                $key = $term->readKey();
                if ($key === '') {
                    continue;
                }
                $hex = strtoupper(bin2hex($key));
                $printable = preg_replace_callback(
                    '/[^\x20-\x7e]/',
                    static fn(array $m): string => sprintf('\\x%02X', ord($m[0])),
                    $key,
                );
                $term->write(sprintf(
                    "got %2d byte%s  hex=%-16s repr=%s\r\n",
                    strlen($key),
                    strlen($key) === 1 ? ' ' : 's',
                    $hex,
                    $printable ?? '<replace failed>',
                ));
                if ($key === 'q') {
                    $term->write("(q pressed — exiting)\r\n");
                    break;
                }
            }
        } finally {
            $term->leave();
        }

        $output->writeln('<info>diagnose finished</info>');
        return 0;
    }
}
