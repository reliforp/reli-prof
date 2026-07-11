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

use PhpCast\NullableCast;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\PhpMeminfoFromRmemExporter;
use Reli\Inspector\Output\MemoryOutput\Report\BinaryReportDataProvider;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Export an `.rmem` capture as a php-meminfo compatible JSON dump
 * (https://github.com/BitOne/php-meminfo), for reuse of analysis
 * infrastructure built around php-meminfo's `bin/analyzer` (summary /
 * query / ref-path / top-children) or other consumers of that format.
 *
 * Equivalent to `inspector:memory … -f meminfo` /
 * `inspector:memory:analyze … -f meminfo` in output, but skips the
 * capture / collect phase: when you already have a saved rmem and
 * just want the php-meminfo view of it, this is the fast path.
 */
final class MemoryExportMeminfoCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:export-meminfo')
            ->setDescription(
                'Export an .rmem capture as a php-meminfo compatible JSON dump'
            )
            ->addArgument(
                'input',
                InputArgument::REQUIRED,
                'path to the input .rmem file',
            )
            ->addArgument(
                'output',
                InputArgument::OPTIONAL,
                'path for the output JSON file (default: stdout)',
            )
            ->addOption(
                'pretty-print',
                null,
                InputOption::VALUE_NEGATABLE,
                'pretty print the result (default: off)',
                false,
            )
            ->addMemoryLimitOption()
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);
        $rmem_path = (string)$input->getArgument('input');
        $json_path = NullableCast::toString($input->getArgument('output'));
        $pretty_print = (bool)$input->getOption('pretty-print');

        if (!file_exists($rmem_path)) {
            $output->writeln("<error>File not found: {$rmem_path}</error>");
            return 1;
        }

        $reader = BinaryReader::open($rmem_path);
        $summary = BinaryReportDataProvider::loadSummary($reader);
        $exporter = new PhpMeminfoFromRmemExporter($reader, $pretty_print);

        $fp = fopen($json_path ?? 'php://stdout', 'w');
        if ($fp === false) {
            $output->writeln("<error>Cannot open output: {$json_path}</error>");
            return 1;
        }
        try {
            $exporter->export($summary, $fp);
        } finally {
            if ($json_path !== null) {
                fclose($fp);
            }
        }
        return 0;
    }
}
