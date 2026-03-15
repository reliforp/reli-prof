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

namespace Reli\Command\Converter;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\CircularReferenceDetector\JsonCircularReferenceDetector;
use Reli\ReliProfiler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CircularReferencesCommand extends Command
{
    public function configure(): void
    {
        $this->setName('converter:circular-references')
            ->setDescription(
                'detect circular references from inspector:memory JSON output'
            )
        ;
        $this->addArgument(
            'input-file',
            InputArgument::OPTIONAL,
            'path to the inspector:memory JSON output file (default: STDIN)',
        );
        $this->addOption(
            'pretty-print',
            null,
            InputOption::VALUE_NEGATABLE,
            'pretty print the result (default: off)',
            false,
        );
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $input_file = $input->getArgument('input-file');

        if ($input_file !== null) {
            if (!is_file($input_file)) {
                $output->writeln("<error>File not found: {$input_file}</error>");
                return 1;
            }
            $json_string = file_get_contents($input_file);
        } else {
            $json_string = stream_get_contents(STDIN);
        }

        if ($json_string === false || $json_string === '') {
            $output->writeln('<error>No input data</error>');
            return 1;
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($json_string, true);
        if ($data === null) {
            $output->writeln('<error>Invalid JSON: ' . json_last_error_msg() . '</error>');
            return 1;
        }

        if (!isset($data['context']) || !is_array($data['context'])) {
            $output->writeln('<error>No "context" key found in input JSON</error>');
            return 1;
        }

        $detector = new JsonCircularReferenceDetector();
        $result = $detector->detect($data['context']);

        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($input->getOption('pretty-print')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $output->write(
            json_encode(
                [
                    'circular_references' => $result,
                    'analyzer' => ReliProfiler::toolSignature(),
                ],
                $flags,
            ),
        );

        return 0;
    }
}
