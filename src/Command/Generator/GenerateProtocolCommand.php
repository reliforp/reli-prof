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

namespace Reli\Command\Generator;

use Reli\Lib\Session\Generator\ProtocolGenerator;
use Reli\Lib\Session\SessionEnumDiscoverer;
use Reli\Lib\Session\SessionGraph;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GenerateProtocolCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('generator:protocol')
            ->setDescription('Generate protocol interfaces and implementations from session type definitions');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $generator = new ProtocolGenerator();
        $enumClasses = SessionEnumDiscoverer::discover(dirname(__DIR__, 3) . '/src');

        if ($enumClasses === []) {
            $output->writeln('<comment>No session protocol enums found.</comment>');
            return 0;
        }

        foreach ($enumClasses as $enumClass) {
            $output->writeln("Processing {$enumClass}...");

            $graph = SessionGraph::fromEnum($enumClass);

            $errors = $graph->verify();
            if ($errors !== []) {
                $output->writeln('<error>Protocol verification failed:</error>');
                foreach ($errors as $error) {
                    $output->writeln("  - {$error}");
                }
                return 1;
            }
            $output->writeln('  Protocol verification: <info>OK</info>');

            $files = $generator->generate($graph);
            foreach ($files as $path => $content) {
                $fullPath = dirname(__DIR__, 3) . '/' . $path;
                $dir = dirname($fullPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($fullPath, $content);
                $output->writeln("  Generated: {$path}");
            }
        }

        $output->writeln('<info>Done.</info>');
        return 0;
    }
}
