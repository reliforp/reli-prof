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

namespace Reli\Converter\BinaryTrace;

use Reli\Converter\ParsedCallTrace;

/**
 * Minimal hand-rolled protobuf encoder for pprof profile.proto.
 *
 * Encodes just enough of the Profile message to be readable by
 * `go tool pprof` and compatible viewers.
 *
 * @see https://github.com/google/pprof/blob/main/proto/profile.proto
 */
final class PprofEncoder
{
    /**
     * Encode traces to pprof protobuf format with streaming aggregation.
     *
     * @param iterable<BinaryTraceSample|ParsedCallTrace> $traces
     * @param (\Closure(): int)|int $sampling_period_us Sampling period in microseconds.
     *        May be a closure for lazy resolution (e.g. from a reader whose header
     *        is only parsed after iteration begins).
     */
    public function encode(iterable $traces, \Closure|int $sampling_period_us = 10000): string
    {
        // Phase 1: streaming aggregation
        /** @var array<string, int> string => string_table_index */
        $string_table = ['' => 0];
        $string_list = [''];

        $intern = function (string $s) use (&$string_table, &$string_list): int {
            if (isset($string_table[$s])) {
                return $string_table[$s];
            }
            $idx = count($string_list);
            $string_table[$s] = $idx;
            $string_list[] = $s;
            return $idx;
        };

        // Intern well-known strings
        $samples_idx = $intern('samples');
        $count_idx = $intern('count');
        $wall_idx = $intern('wall');
        $microseconds_idx = $intern('microseconds');

        /** @var array<string, int> frame_key => function_id */
        $function_map = [];
        /** @var list<array{id: int, name: int, filename: int}> */
        $functions = [];
        $next_function_id = 1; // pprof IDs are 1-based

        /** @var array<string, int> location_key => location_id */
        $location_map = [];
        /** @var list<array{id: int, function_id: int, line: int}> */
        $locations = [];
        $next_location_id = 1;

        /** @var array<string, int> sample_key => index in $samples */
        $sample_map = [];
        /** @var list<array{location_ids: int[], count: int}> */
        $samples = [];

        $max_accumulated_us = 0;

        foreach ($traces as $item) {
            if ($item instanceof BinaryTraceSample) {
                $trace = $item->trace;
                if ($item->accumulated_timestamp_us !== null) {
                    $max_accumulated_us = max($max_accumulated_us, $item->accumulated_timestamp_us);
                }
            } else {
                $trace = $item;
            }

            $location_ids = [];
            // pprof: first location is the leaf (innermost), same as call_frames[0]
            foreach ($trace->call_frames as $frame) {
                $func_key = $frame->function_name . "\0" . $frame->file_name;
                if (!isset($function_map[$func_key])) {
                    $fid = $next_function_id++;
                    $function_map[$func_key] = $fid;
                    $functions[] = [
                        'id' => $fid,
                        'name' => $intern($frame->function_name),
                        'filename' => $intern($frame->file_name),
                    ];
                }
                $func_id = $function_map[$func_key];

                $loc_key = $func_key . "\0" . $frame->lineno;
                if (!isset($location_map[$loc_key])) {
                    $lid = $next_location_id++;
                    $location_map[$loc_key] = $lid;
                    $locations[] = [
                        'id' => $lid,
                        'function_id' => $func_id,
                        'line' => $frame->lineno,
                    ];
                }
                $location_ids[] = $location_map[$loc_key];
            }

            $sample_key = implode(',', $location_ids);
            if (isset($sample_map[$sample_key])) {
                $samples[$sample_map[$sample_key]]['count']++;
            } else {
                $sample_map[$sample_key] = count($samples);
                $samples[] = ['location_ids' => $location_ids, 'count' => 1];
            }
        }

        // Phase 2: encode protobuf
        $profile = '';

        // field 1: sample_type (repeated ValueType message)
        // ValueType: type=1(int64), unit=2(int64)
        $vt = $this->encodeVarintField(1, $samples_idx)
            . $this->encodeVarintField(2, $count_idx);
        $profile .= $this->encodeBytesField(1, $vt);

        // field 2: sample (repeated Sample message)
        foreach ($samples as $sample) {
            $s = '';
            // Sample.location_id: field 1, packed repeated uint64
            $packed = '';
            foreach ($sample['location_ids'] as $lid) {
                $packed .= Varint::encode($lid);
            }
            $s .= $this->encodeBytesField(1, $packed);
            // Sample.value: field 2, packed repeated int64
            $s .= $this->encodeBytesField(2, Varint::encode($sample['count']));
            $profile .= $this->encodeBytesField(2, $s);
        }

        // field 4: location (repeated Location message)
        foreach ($locations as $loc) {
            $l = '';
            // Location.id: field 1
            $l .= $this->encodeVarintField(1, $loc['id']);
            // Location.line: field 4 (repeated Line message)
            // Line: function_id=1, line=2
            $line = $this->encodeVarintField(1, $loc['function_id'])
                . $this->encodeVarintField(2, $loc['line']);
            $l .= $this->encodeBytesField(4, $line);
            $profile .= $this->encodeBytesField(4, $l);
        }

        // field 5: function (repeated Function message)
        foreach ($functions as $func) {
            $f = '';
            // Function.id: field 1
            $f .= $this->encodeVarintField(1, $func['id']);
            // Function.name: field 2
            $f .= $this->encodeVarintField(2, $func['name']);
            // Function.filename: field 4
            $f .= $this->encodeVarintField(4, $func['filename']);
            $profile .= $this->encodeBytesField(5, $f);
        }

        // field 6: string_table (repeated string)
        foreach ($string_list as $str) {
            $profile .= $this->encodeBytesField(6, $str);
        }

        // field 9: time_nanos (int64) - not available from binary trace, set to 0
        // field 10: duration_nanos (int64)
        if ($max_accumulated_us > 0) {
            $profile .= $this->encodeVarintField(10, $max_accumulated_us * 1000);
        }

        // field 11: period_type (ValueType message)
        $pt = $this->encodeVarintField(1, $wall_idx)
            . $this->encodeVarintField(2, $microseconds_idx);
        $profile .= $this->encodeBytesField(11, $pt);

        // field 12: period (int64)
        $resolved_period = $sampling_period_us instanceof \Closure
            ? $sampling_period_us()
            : $sampling_period_us;
        $profile .= $this->encodeVarintField(12, $resolved_period);

        return $profile;
    }

    /**
     * Encode a varint wire-type field: (field_num << 3 | 0) + varint
     */
    private function encodeVarintField(int $field_number, int $value): string
    {
        return Varint::encode(($field_number << 3) | 0) . Varint::encode($value);
    }

    /**
     * Encode a length-delimited wire-type field: (field_num << 3 | 2) + length + bytes
     */
    private function encodeBytesField(int $field_number, string $bytes): string
    {
        return Varint::encode(($field_number << 3) | 2)
            . Varint::encode(strlen($bytes))
            . $bytes;
    }
}
