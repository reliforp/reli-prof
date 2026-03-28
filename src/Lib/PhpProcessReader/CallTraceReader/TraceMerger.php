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

namespace Reli\Lib\PhpProcessReader\CallTraceReader;

use Reli\Lib\Dwarf\NativeTrace;

final class TraceMerger
{
    /**
     * PHP VM boundary functions where PHP frames should be inserted.
     *
     * execute_ex: the opcode dispatcher — each call runs one PHP function's opcodes.
     * ZEND_DO_*CALL handlers: opcode handlers that invoke PHP functions.
     *   - ICALL: calls internal (C) functions like strlen, usleep
     *   - UCALL: calls user functions (triggers another execute_ex)
     *   - FCALL: general function call
     *   - INCLUDE_OR_EVAL: include/require/eval (triggers another execute_ex)
     *
     * zend_execute is intentionally excluded — it's just a thin wrapper that
     * calls execute_ex for the top-level script. All PHP execution happens
     * inside execute_ex, so zend_execute doesn't correspond to a PHP frame.
     */
    private const PHP_VM_FUNCTIONS = [
        'execute_ex',
        'ZEND_DO_FCALL_SPEC_RETVAL_UNUSED_HANDLER',
        'ZEND_DO_FCALL_SPEC_RETVAL_USED_HANDLER',
        'ZEND_DO_FCALL_SPEC_OBSERVER_HANDLER',
        'ZEND_DO_ICALL_SPEC_RETVAL_UNUSED_HANDLER',
        'ZEND_DO_ICALL_SPEC_RETVAL_USED_HANDLER',
        'ZEND_DO_UCALL_SPEC_RETVAL_UNUSED_HANDLER',
        'ZEND_DO_UCALL_SPEC_RETVAL_USED_HANDLER',
        'ZEND_DO_UCALL_SPEC_OBSERVER_HANDLER',
        'ZEND_INCLUDE_OR_EVAL_SPEC_CONST_HANDLER',
        'ZEND_INCLUDE_OR_EVAL_SPEC_TMPVAR_HANDLER',
        'ZEND_INCLUDE_OR_EVAL_SPEC_CV_HANDLER',
    ];

    public function merge(NativeTrace $nativeTrace, CallTrace $phpTrace): MergedCallTrace
    {
        $merged = [];
        $php_frame_index = 0;
        $php_frame_count = count($phpTrace->call_frames);

        $native_frames = $nativeTrace->frames;
        $native_count = count($native_frames);

        // Count total VM boundaries in native trace to determine how many
        // PHP frames each boundary should consume.
        $total_boundaries = 0;
        for ($i = 0; $i < $native_count; $i++) {
            if ($this->isPhpVmFunction($native_frames[$i]->symbolName ?? '')) {
                $total_boundaries++;
            }
        }

        $first_boundary = true;
        $remaining_boundaries = $total_boundaries;

        for ($i = 0; $i < $native_count; $i++) {
            $native_frame = $native_frames[$i];
            $symbol_name = $native_frame->symbolName ?? '';

            if ($this->isPhpVmFunction($symbol_name) && $php_frame_index < $php_frame_count) {
                if ($first_boundary) {
                    // At the first (innermost) VM boundary, consume any "excess"
                    // PHP frames that lack their own boundary. This handles
                    // internal function calls (ICALL) whose opcode handlers were
                    // inlined into execute_ex and don't appear in the native trace.
                    // e.g., <main> calls usleep(): 2 PHP frames but only 1 execute_ex
                    //   → consume usleep (excess) + <main> (this boundary's frame)
                    $excess = $php_frame_count - $remaining_boundaries;
                    for ($j = 0; $j < $excess; $j++) {
                        $merged[] = MergedCallFrame::fromPhp($phpTrace->call_frames[$php_frame_index]);
                        $php_frame_index++;
                    }
                    $first_boundary = false;
                }
                // Consume one PHP frame for this VM boundary
                if ($php_frame_index < $php_frame_count) {
                    $merged[] = MergedCallFrame::fromPhp($phpTrace->call_frames[$php_frame_index]);
                    $php_frame_index++;
                }
                $remaining_boundaries--;
            }

            $merged[] = MergedCallFrame::fromNative(
                new NativeCallFrame(
                    $native_frame->symbolName ?? sprintf('0x%x', $native_frame->ip),
                    $native_frame->moduleName,
                    $native_frame->offset,
                    $native_frame->ip,
                )
            );
        }

        // Add remaining PHP frames that weren't matched
        while ($php_frame_index < $php_frame_count) {
            $merged[] = MergedCallFrame::fromPhp($phpTrace->call_frames[$php_frame_index]);
            $php_frame_index++;
        }

        return new MergedCallTrace(...$merged);
    }

    private function isPhpVmFunction(string $symbolName): bool
    {
        if (in_array($symbolName, self::PHP_VM_FUNCTIONS, true)) {
            return true;
        }

        if (str_starts_with($symbolName, 'ZEND_DO_') && str_contains($symbolName, 'CALL')) {
            return true;
        }

        return false;
    }
}
