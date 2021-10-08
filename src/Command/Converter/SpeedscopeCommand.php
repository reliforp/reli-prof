<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Command\Converter;

use PhpCast\Cast;
use PhpProfiler\Lib\PhpInternals\Opcodes\OpcodeV80;
use PhpProfiler\Lib\PhpInternals\Types\Zend\Opline;
use PhpProfiler\Lib\PhpProcessReader\CallFrame;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;
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
        $output->write(
            json_encode(
                $this->collectFrames(
                    $this->getTraceIterator(STDIN)
                )
            )
        );
        return 0;
    }

    /**
     * @param resource $fp
     * @return iterable<int, CallTrace>
     */
    private function getTraceIterator($fp): iterable
    {
        $buffer = [];
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line !== '') {
                $buffer[] = $line;
                continue;
            }
            yield $this->parsePhpSpyCompatible($buffer);
            $buffer = [];
        }
    }

    /** @param string[] $buffer */
    private function parsePhpSpyCompatible(array $buffer): CallTrace
    {
        $frames = [];
        foreach ($buffer as $line_buffer) {
            $result = explode(' ', $line_buffer);
            [$depth, $name, $file_line] = $result;
            if ($depth === '#') { // comment
                continue;
            }
            [$file, $line] = explode(':', $file_line);
            $frames[] = new CallFrame(
                '',
                $name,
                $file,
                new Opline(0, 0, 0, 0, Cast::toInt($line), new OpcodeV80(0), 0, 0, 0),
            );
        }
        return new CallTrace(...$frames);
    }

    /** @param iterable<CallTrace> $call_frames */
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
                    'name' => $call_frame->getFullyQualifiedFunctionName(),
                    'file' => $call_frame->file_name,
                    'line' => $call_frame->getLineno(),
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
                'name' => 'test',
                'unit' => 'none',
                'startValue' => 0,
                'endValue' => $counter,
                'samples' => $sampled_stacks,
                'weights' => array_fill(0, count($sampled_stacks), 1),
            ]]
        ];
    }
}
