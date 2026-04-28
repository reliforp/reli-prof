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

use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Format;
use Reli\Inspector\Output\MemoryOutput\BinaryFormat\Reader as BinaryReader;
use Reli\Inspector\Output\MemoryOutput\Comparison\BinaryComparisonDataProvider;
use Reli\Inspector\Output\MemoryOutput\Comparison\ComparisonDataProvider;
use Reli\Inspector\Output\MemoryOutput\Comparison\ComparisonGenerator;
use Reli\Inspector\Output\MemoryOutput\Comparison\Formatter\JsonComparisonFormatter;
use Reli\Inspector\Output\MemoryOutput\Comparison\Formatter\TextComparisonFormatter;
use Reli\Inspector\Output\MemoryOutput\Comparison\PdoComparisonDataProvider;
use Reli\Inspector\Output\MemoryOutput\Report\Substrate\SizeFormatter;
use Reli\Lib\Log\Log;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MemoryCompareCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:compare')
            ->setDescription('compare two memory snapshots (.rmem or SQLite .db/.sqlite)')
        ;
        $this->addArgument(
            'baseline',
            InputArgument::REQUIRED,
            'path to the baseline snapshot (.rmem or SQLite .db/.sqlite)'
        );
        $this->addArgument(
            'target',
            InputArgument::OPTIONAL,
            'path to the target snapshot (.rmem or SQLite .db/.sqlite); omit to compare run IDs within the same file'
        );
        $this->addOption(
            'run-id-baseline',
            null,
            InputOption::VALUE_REQUIRED,
            'run ID for baseline (default: 1)',
            '1',
        );
        $this->addOption(
            'run-id-target',
            null,
            InputOption::VALUE_REQUIRED,
            'run ID for target (default: 1)',
            '1',
        );
        $this->addOption(
            'output-format',
            'f',
            InputOption::VALUE_REQUIRED,
            'output format: report (text) or report-json',
            'report',
        );
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'output file path (default: stdout)',
        );
        $this->addOption(
            'pretty-print',
            null,
            InputOption::VALUE_NEGATABLE,
            'pretty print JSON output (default: on)',
            true,
        );
        $this->addOption(
            'threshold',
            null,
            InputOption::VALUE_REQUIRED,
            'minimum change percentage to report (default: 0)',
            '0',
        );
        $this->addOption(
            'full-analysis',
            null,
            InputOption::VALUE_NEGATABLE,
            'run all analysis passes for both snapshots (default: off)',
            false,
        );
        $this->addOption(
            'memory-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'set PHP memory_limit for analysis (e.g. 2G, 512M)',
        );
        $this->addOption(
            'ffi-csr',
            null,
            InputOption::VALUE_NEGATABLE,
            'force FFI CSR graph substrate (default: auto)',
        );
        $this->addOption(
            'diff-nodes',
            null,
            InputOption::VALUE_REQUIRED,
            'show sample nodes present only in one side: "baseline-only" or "target-only" (binary .rmem only)',
        );
        $this->addOption(
            'diff-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'max samples to show for --diff-nodes (default: 20)',
            '20',
        );
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $memory_limit */
        $memory_limit = $input->getOption('memory-limit');
        if (is_string($memory_limit) && $memory_limit !== '') {
            ini_set('memory_limit', $memory_limit);
        }

        Log::info('start memory:compare command');

        $baseline_file = (string)$input->getArgument('baseline');
        /** @var string|null $target_arg */
        $target_arg = $input->getArgument('target');
        $target_file = $target_arg !== null ? $target_arg : $baseline_file;

        if (!file_exists($baseline_file)) {
            $output->writeln("<error>Baseline database not found: {$baseline_file}</error>");
            return 1;
        }
        if ($target_file !== $baseline_file && !file_exists($target_file)) {
            $output->writeln("<error>Target database not found: {$target_file}</error>");
            return 1;
        }

        $baseline_run_id = (int)$input->getOption('run-id-baseline');
        $target_run_id = (int)$input->getOption('run-id-target');
        $format = (string)$input->getOption('output-format');
        /** @var string|null $output_path */
        $output_path = $input->getOption('output');
        $pretty = (bool)$input->getOption('pretty-print');
        $threshold = (float)$input->getOption('threshold');
        $full_analysis = (bool)$input->getOption('full-analysis');
        /** @var bool|null $ffi_csr */
        $ffi_csr = $input->getOption('ffi-csr');

        $baseline_provider = $this->createProvider($baseline_file, $baseline_run_id);
        $target_provider = $this->createProvider($target_file, $target_run_id);

        $generator = new ComparisonGenerator();
        $result = $generator->compare(
            $baseline_provider,
            $target_provider,
            $threshold,
            $full_analysis,
            $ffi_csr,
        );

        $formatter = match ($format) {
            'report' => new TextComparisonFormatter(),
            'report-json' => new JsonComparisonFormatter($pretty),
            default => throw new \RuntimeException(
                "Unsupported format: {$format} (supported: report, report-json)"
            ),
        };

        $formatted = $formatter->format($result);

        if ($output_path !== null) {
            file_put_contents($output_path, $formatted);
            $output->writeln("<info>Comparison report written to {$output_path}</info>");
        } else {
            $output->write($formatted);
        }

        // Node diff for binary files
        /** @var string|null $diff_nodes */
        $diff_nodes = $input->getOption('diff-nodes');
        if ($diff_nodes !== null) {
            $diff_limit = (int)$input->getOption('diff-limit');
            $this->printNodeDiff(
                $output,
                $baseline_file,
                $target_file,
                $diff_nodes,
                $diff_limit,
            );
        }

        Log::info('end memory:compare command');
        return 0;
    }

    /**
     * @psalm-suppress MixedAssignment, MixedArrayAccess, PossiblyInvalidArrayAccess
     */
    private function printNodeDiff(
        OutputInterface $output,
        string $baseline_file,
        string $target_file,
        string $side,
        int $limit,
    ): void {
        if (!$this->isBinaryFile($baseline_file) || !$this->isBinaryFile($target_file)) {
            $output->writeln('<error>--diff-nodes requires binary (.rmem) files</error>');
            return;
        }

        $baseline_reader = BinaryReader::open($baseline_file);
        $target_reader = BinaryReader::open($target_file);

        // Build address sets from locations sections
        $baseline_addrs = $this->collectAddresses($baseline_reader);
        $target_addrs = $this->collectAddresses($target_reader);

        if ($side === 'baseline-only') {
            $diff = array_diff_key($baseline_addrs, $target_addrs);
            $reader = $baseline_reader;
            $label = 'Baseline-only';
        } elseif ($side === 'target-only') {
            $diff = array_diff_key($target_addrs, $baseline_addrs);
            $reader = $target_reader;
            $label = 'Target-only';
        } else {
            $output->writeln('<error>--diff-nodes must be "baseline-only" or "target-only"</error>');
            return;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<comment>%s nodes: %d total (showing %d)</comment>',
            $label,
            count($diff),
            min(count($diff), $limit),
        ));

        if ($diff === []) {
            $output->writeln('  (none)');
            return;
        }

        $dict = $reader->getStringDict();
        $locations = $this->loadLocationsByAddress($reader);
        $shown = 0;

        foreach ($diff as $addr => $_) {
            if ($shown >= $limit) {
                break;
            }
            $loc = $locations[$addr] ?? null;
            if ($loc === null) {
                continue;
            }
            $type_name = $dict->lookup($loc['location_type_id']) ?? '?';
            $class_name = $loc['class_id'] !== Format::NULL_STRING_ID
                ? ($dict->lookup($loc['class_id']) ?? '')
                : '';
            $size = $loc['size'];
            $node_id = $loc['node_id'];

            $display = sprintf(
                '  node=%d addr=0x%x type=%s size=%s',
                $node_id,
                $addr,
                $type_name,
                SizeFormatter::format($size),
            );
            if ($class_name !== '') {
                $display .= " class={$class_name}";
            }
            $output->writeln($display);
            $shown++;
        }
    }

    /**
     * @return array<int, true> address => true
     * @psalm-suppress MixedAssignment, PossiblyInvalidArrayAccess
     */
    private function collectAddresses(BinaryReader $reader): array
    {
        if (!$reader->hasSection(Format::SECTION_LOCATIONS)) {
            return [];
        }
        $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
        $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::LOCATION_ROW_SIZE;
            /** @var array{1: int} */
            $addr_u = unpack('P', $data, $off + 12); // address at offset 12
            $result[$addr_u[1]] = true;
        }
        return $result;
    }

    /**
     * @return array<int, array{node_id: int, location_type_id: int, class_id: int, size: int}>
     * @psalm-suppress MixedAssignment, PossiblyInvalidArrayAccess
     */
    private function loadLocationsByAddress(BinaryReader $reader): array
    {
        if (!$reader->hasSection(Format::SECTION_LOCATIONS)) {
            return [];
        }
        $data = $reader->getSectionData(Format::SECTION_LOCATIONS);
        $count = $reader->getSectionElementCount(Format::SECTION_LOCATIONS);
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $off = $i * Format::LOCATION_ROW_SIZE;
            /** @var array{1: int} */
            $node_id_u = unpack('V', $data, $off);
            /** @var array{1: int} */
            $type_id_u = unpack('V', $data, $off + 4);
            /** @var array{1: int} */
            $class_id_u = unpack('V', $data, $off + 8);
            /** @var array{1: int} */
            $addr_u = unpack('P', $data, $off + 12);
            /** @var array{1: int} */
            $size_u = unpack('P', $data, $off + 20);

            $result[$addr_u[1]] = [
                'node_id' => $node_id_u[1],
                'location_type_id' => $type_id_u[1],
                'class_id' => $class_id_u[1],
                'size' => (int)$size_u[1],
            ];
        }
        return $result;
    }

    private function createProvider(string $path, int $run_id): ComparisonDataProvider
    {
        if (str_ends_with($path, '.rmem') || $this->isBinaryFile($path)) {
            return new BinaryComparisonDataProvider($path);
        }
        $db = new \PDO("sqlite:{$path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA mmap_size = 268435456');
        return new PdoComparisonDataProvider($db, $run_id);
    }

    private function isBinaryFile(string $path): bool
    {
        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return false;
        }
        $magic = fread($fp, 4);
        fclose($fp);
        return $magic === 'RMEM';
    }
}
