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

use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\Comparison\BinShapeCountsSnapshot;
use Reli\Lib\PhpInternals\Types\Zend\ZendMmBinsInfo;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drill-down query for the bin walker output persisted in an rmem file.
 *
 * `inspector:memory:bin-query <rmem> --bin=N [--shape=...] [--sample=K]`
 *
 * Lists sample addresses the bin walker captured for the given bin
 * (and optionally a single shape label). Pipes naturally into
 * `inspector:memory:peek --rdump=...` for byte-level inspection — this
 * is the design's E.1 deliverable + the validator-flagged drill-down
 * gap that left them hand-poking `/proc/<pid>/mem` to look at more
 * than one slot.
 *
 * Sample addresses are capped per (bin, shape) at analyze time
 * (see {@see \Reli\Lib\PhpProcessReader\PhpMemoryReader\BinWalk\ZendMmBinWalker::PER_SHAPE_SAMPLE_CAP}),
 * so this command never returns more than that cap regardless of how
 * many slots the bin actually held.
 */
final class MemoryBinQueryCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    public function __construct()
    {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:bin-query')
            ->setDescription(
                'list sample slot addresses captured by the bin walker'
                . ' for a given ZendMM bin'
            )
        ;
        $this->addArgument(
            'rmem-file',
            InputArgument::REQUIRED,
            'path to an rmem file produced by inspector:memory:analyze',
        );
        $this->addOption(
            'bin',
            'b',
            InputOption::VALUE_REQUIRED,
            'bin number (0..29). Pass --list-bins to see what is available.',
        );
        $this->addOption(
            'shape',
            's',
            InputOption::VALUE_REQUIRED,
            'restrict to a single inferred shape label'
                . ' (e.g. "Bucket(zval IS_STRING)")',
        );
        $this->addOption(
            'sample',
            null,
            InputOption::VALUE_REQUIRED,
            'maximum number of sample addresses to print (default 16)',
            '16',
        );
        $this->addOption(
            'list-bins',
            null,
            InputOption::VALUE_NONE,
            'list every bin that has captured samples and exit',
        );
        $this->addMemoryLimitOption();
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);

        /** @var string $rmem_file */
        $rmem_file = $input->getArgument('rmem-file');

        $snapshot = $this->loadShapeCounts($rmem_file);
        if ($snapshot === null || $snapshot === []) {
            $output->writeln(
                '<error>no bin_shape_counts entry found in this rmem file</error>',
            );
            $output->writeln(
                '  (was it produced before the per-slot scan landed?'
                . ' re-run inspector:memory:analyze on a fresh rdump.)',
            );
            return 1;
        }

        if ((bool)$input->getOption('list-bins')) {
            $this->listBins($output, $snapshot);
            return 0;
        }

        $bin_opt = $input->getOption('bin');
        if ($bin_opt === null || $bin_opt === '') {
            $output->writeln(
                '<error>--bin is required (or pass --list-bins)</error>',
            );
            return 1;
        }
        $bin = (int)$bin_opt;
        if (!isset($snapshot[$bin])) {
            $output->writeln(sprintf(
                '<error>no captures for bin %d in this rmem file</error>',
                $bin,
            ));
            return 1;
        }

        $shape_filter = $input->getOption('shape');
        $shape_filter = is_string($shape_filter) && $shape_filter !== ''
            ? $shape_filter
            : null;

        $sample_opt = $input->getOption('sample');
        $sample_cap = is_string($sample_opt) || is_int($sample_opt)
            ? max(1, (int)$sample_opt)
            : 16;

        $this->dumpBin(
            $output,
            $bin,
            $snapshot[$bin],
            $shape_filter,
            $sample_cap,
        );
        return 0;
    }

    /**
     * @return array<int, array<string, array{
     *     count: int,
     *     reachable_count: int,
     *     confidence: string,
     *     sample_addrs: list<int>
     * }>>|null
     */
    private function loadShapeCounts(string $rmem_file): ?array
    {
        if (!is_readable($rmem_file)) {
            throw new \RuntimeException("rmem file not readable: {$rmem_file}");
        }
        $reader = BinaryReader::open($rmem_file);
        if (!$reader->hasSection(Format::SECTION_SUMMARY)) {
            return null;
        }
        $data = $reader->getSectionData(Format::SECTION_SUMMARY);
        $dict = $reader->getStringDict();

        $offset = 0;
        /** @var array{1: int} $cnt */
        $cnt = unpack('V', $data, $offset);
        $entry_count = $cnt[1];
        $offset += 4;

        for ($i = 0; $i < $entry_count; $i++) {
            /** @var array{1: int} $kid */
            $kid = unpack('V', $data, $offset);
            $offset += 4;
            /** @var array{1: int} $vid */
            $vid = unpack('V', $data, $offset);
            $offset += 4;

            $key = $dict->lookup($kid[1]);
            if ($key !== 'bin_shape_counts') {
                continue;
            }
            $value = $dict->lookup($vid[1]);
            if (!is_string($value) || $value === '') {
                return null;
            }
            return BinShapeCountsSnapshot::decode($value);
        }
        return null;
    }

    /**
     * @param array<int, array<string, array{
     *     count: int,
     *     reachable_count: int,
     *     confidence: string,
     *     sample_addrs: list<int>
     * }>> $snapshot
     */
    private function listBins(OutputInterface $output, array $snapshot): void
    {
        $output->writeln('<info>Bins with captured samples:</info>');
        $output->writeln('');
        $output->writeln(sprintf(
            '  %3s  %8s  %s',
            'Bin',
            'Size',
            'Shapes (count)',
        ));
        $output->writeln('  ' . str_repeat('-', 70));
        $bins = array_keys($snapshot);
        sort($bins);
        foreach ($bins as $bin) {
            $shapes = $snapshot[$bin];
            $size = ZendMmBinsInfo::getSize($bin);
            $shape_summary = [];
            foreach ($shapes as $label => $row) {
                $shape_summary[] = sprintf('%s(%d)', $label, $row['count']);
            }
            // Cap rendered shape list to keep the line readable.
            $shown = array_slice($shape_summary, 0, 3);
            $extra = count($shape_summary) - count($shown);
            $line = implode(', ', $shown);
            if ($extra > 0) {
                $line .= sprintf(', … %d more', $extra);
            }
            $output->writeln(sprintf(
                '  %3d  %5d B  %s',
                $bin,
                $size,
                $line,
            ));
        }
    }

    /**
     * @param array<string, array{
     *     count: int,
     *     reachable_count: int,
     *     confidence: string,
     *     sample_addrs: list<int>
     * }> $bin_data
     */
    private function dumpBin(
        OutputInterface $output,
        int $bin,
        array $bin_data,
        ?string $shape_filter,
        int $sample_cap,
    ): void {
        $size = ZendMmBinsInfo::getSize($bin);
        $output->writeln(sprintf(
            '<info>bin[%d] %d B  (%d shapes captured)</info>',
            $bin,
            $size,
            count($bin_data),
        ));
        $output->writeln('');

        $shown_any = false;
        foreach ($bin_data as $label => $row) {
            if ($shape_filter !== null && $label !== $shape_filter) {
                continue;
            }
            $shown_any = true;
            $orphan = $row['count'] - $row['reachable_count'];
            $output->writeln(sprintf(
                '  %s  [%s]  count=%d  orphan=%d  reachable=%d',
                $label,
                strtoupper($row['confidence']),
                $row['count'],
                $orphan,
                $row['reachable_count'],
            ));
            $samples = $row['sample_addrs'];
            if ($samples === []) {
                $output->writeln(
                    '    (no sample addresses persisted for this shape)',
                );
                continue;
            }
            $cap = min($sample_cap, count($samples));
            for ($i = 0; $i < $cap; $i++) {
                $output->writeln(sprintf('    0x%016x', $samples[$i]));
            }
            $remaining = count($samples) - $cap;
            if ($remaining > 0) {
                $output->writeln(sprintf(
                    '    … %d more sample address%s available'
                        . ' (raise --sample to see them)',
                    $remaining,
                    $remaining === 1 ? '' : 'es',
                ));
            }
            $output->writeln('');
        }

        if (!$shown_any) {
            $output->writeln(sprintf(
                '<error>no shape "%s" in bin[%d]</error>',
                $shape_filter ?? '(none)',
                $bin,
            ));
        }
    }
}
