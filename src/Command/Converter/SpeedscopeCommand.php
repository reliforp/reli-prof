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

use Reli\Converter\ParsedCallTrace;
use Reli\Converter\PhpSpyCompatibleParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SpeedscopeCommand extends Command
{
    public function configure(): void
    {
        $this->setName('converter:speedscope')
            ->setDescription('convert traces to the speedscope file format')
        ;
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $parser = new PhpSpyCompatibleParser();
        $output->write(
            json_encode(
                $this->collectFrames(
                    $parser->parseFile(STDIN)
                )
            )
        );
        return 0;
    }

    /** @param iterable<ParsedCallTrace> $call_frames */
    private function collectFrames(iterable $call_frames): array
    {
        $mapper = fn (array $value): string => \json_encode($value);
        $trace_map = [];
        $result_frames = [];
        $sampled_stacks = [];
        $counter = 0;
        foreach ($call_frames as $frames) {
            $sampled_stack = [];
            foreach ($frames->call_frames as $call_frame) {
                    $frame = [
                    'name' => $call_frame->function_name,
                    'file' => $call_frame->file_name,
                    'line' => $call_frame->lineno,
                    ];
                    $mapper_key = $mapper($frame);
                    if (!isset($trace_map[$mapper_key])) {
                        $result_frames[] = $frame;
                        $trace_map[$mapper_key] = array_key_last($result_frames);
                    }
                    $sampled_stack[] = $trace_map[$mapper_key];
            }
            $sampled_stacks[] = \array_reverse($sampled_stack);
            $counter++;
        }
        return [
            "\$schema" => "https://www.speedscope.app/file-format-schema.json",
            'shared' => [
                'frames' => $result_frames,
            ],
            'profiles' => [[
                'type' => 'sampled',
                'name' => 'php profile',
                'unit' => 'none',
                'startValue' => 0,
                'endValue' => $counter,
                'samples' => $sampled_stacks,
                'weights' => array_fill(0, count($sampled_stacks), 1),
            ]]
        ];
    }
}
