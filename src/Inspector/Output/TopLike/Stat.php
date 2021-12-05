<?php

/**
 * This file is part of the sj-i/ package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Inspector\Output\TopLike;

use PhpProfiler\Lib\PhpProcessReader\CallFrame;
use PhpProfiler\Lib\PhpProcessReader\CallTrace;

final class Stat
{
    /** @param array<string, FunctionEntry> $function_entries */
    public function __construct(
        public array $function_entries = [],
        public int $sample_count = 0,
        public int $total_count = 0,
    ) {
    }

    public function addTrace(CallTrace $call_trace): void
    {
        $this->sample_count++;
        foreach ($call_trace->call_frames as $frame_number => $call_frame) {
            $this->addFrame($call_frame, $frame_number === 0);
        }
    }

    private function addFrame(CallFrame $call_frame, bool $is_first_frame): void
    {
        $name = $call_frame->getFullyQualifiedFunctionName();
        if (!isset($this->function_entries[$name])) {
            $this->function_entries[$name] = new FunctionEntry(
                $name,
                $call_frame->file_name,
                $call_frame->getLineno(),
            );
        }
        if ($is_first_frame) {
            $this->function_entries[$name]->count_exclusive++;
        }
        $this->function_entries[$name]->count_inclusive++;
    }

    public function updateStat(): void
    {
        if (count($this->function_entries) === 0) {
            return;
        }

        $this->calculateEntryTotals();
        $this->sort();
        $this->updateTotalSampleCount();
    }


    public function sort(): void
    {
        \uasort($this->function_entries, function (FunctionEntry $a, FunctionEntry $b) {
            if ($b->count_exclusive === $a->count_exclusive) {
                if ($b->count_inclusive === $a->count_inclusive) {
                    if ($b->total_count_exclusive === $a->total_count_exclusive) {
                        return $b->total_count_inclusive <=> $a->total_count_inclusive;
                    }
                    return $b->total_count_exclusive <=> $a->total_count_exclusive;
                }
                return $b->count_inclusive <=> $a->count_inclusive;
            }
            return $b->count_exclusive <=> $a->count_exclusive;
        });
    }

    public function calculateEntryTotals(): void
    {
        foreach ($this->function_entries as $function_entry) {
            $function_entry->total_count_exclusive += $function_entry->count_exclusive;
            $function_entry->total_count_inclusive += $function_entry->count_inclusive;
            $function_entry->percent_exclusive =
                $this->sample_count < 1
                ? 0.0
                : 100.0 * $function_entry->count_exclusive / $this->sample_count
            ;
        }
    }

    public function updateTotalSampleCount(): void
    {
        $this->total_count += $this->sample_count;
    }

    public function clearCurrentSamples(): void
    {
        $this->sample_count = 0;
        foreach ($this->function_entries as $function_entry) {
            $function_entry->count_exclusive = 0;
            $function_entry->count_inclusive = 0;
        }
    }
}
