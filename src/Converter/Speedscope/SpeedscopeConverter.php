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

namespace Reli\Converter\Speedscope;

use Reli\Converter\ParsedCallTrace;

class SpeedscopeConverter
{
    /** @param iterable<ParsedCallTrace> $call_frames */
    public function collectFrames(iterable $call_frames): array
    {
        $mapper = fn(array $value): string | false => \json_encode(
            $value,
        );
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
                if ($mapper_key === false) {
                    throw new SpeedscopeConverterException(
                        'json_encode failed at '
                        . ($call_frame->original_context?->toString() ?? 'unknown location')
                        . ': '
                        . \json_last_error_msg()
                    );
                }
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
