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

use PhpCast\Cast;
use Reli\Inspector\MemoryDump\MemoryDumpNormalizer;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Normalize an RDUMP dump file by merging overlapping regions into
 * a disjoint set. Older dumps may contain duplicate region entries
 * that force the analyzer into O(n) linear scans; this command
 * rewrites them so binary search always works.
 */
final class MemoryDumpNormalizeCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Minimal;
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:memory:normalize-dump')
            ->setDescription('Rewrite an RDUMP dump with merged disjoint regions')
            ->addArgument(
                'input',
                InputArgument::REQUIRED,
                'path to the input .rdump dump file',
            )
            ->addArgument(
                'output',
                InputArgument::OPTIONAL,
                'path for the output file (default: overwrite input)',
            )
            ->addMemoryLimitOption()
        ;
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);
        $input_path = (string) $input->getArgument('input');
        /** @var string|null $output_arg */
        $output_arg = $input->getArgument('output');
        $output_path = $output_arg ?? $input_path;

        if (!file_exists($input_path)) {
            $output->writeln("<error>File not found: {$input_path}</error>");
            return 1;
        }

        $normalizer = new MemoryDumpNormalizer();
        $result = $normalizer->normalize($input_path, $output_path);

        $output->writeln(sprintf(
            '<info>%d regions → %d merged regions (%.2f MB)</info>',
            $result['original_count'],
            $result['merged_count'],
            Cast::toFloat($result['total_bytes']) / 1024.0 / 1024.0,
        ));

        if ($result['original_count'] === $result['merged_count']) {
            $output->writeln('<comment>No overlapping regions found — dump was already normalized.</comment>');
        }

        return 0;
    }
}
