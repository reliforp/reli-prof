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
     * PHP VM execution function names that indicate a PHP frame boundary.
     * When we see these in the native trace, the next PHP frame corresponds.
     */
    private const PHP_VM_FUNCTIONS = [
        'execute_ex',
        'zend_execute',
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
        $php_frame_index = 0; // start from innermost (current) frame
        $php_frame_count = count($phpTrace->call_frames);

        // Native frames are ordered top-to-bottom (innermost/current first)
        // PHP call_frames are also ordered innermost first
        // Match them in the same direction: inner to outer
        $native_frames = $nativeTrace->frames;
        $native_count = count($native_frames);

        for ($i = 0; $i < $native_count; $i++) {
            $native_frame = $native_frames[$i];
            $symbol_name = $native_frame->symbolName ?? '';

            // If this is a PHP VM execution function, insert the PHP frame BEFORE it.
            // The VM boundary (execute_ex, zend_execute, etc.) is the caller that
            // dispatches/runs the PHP function, so the PHP frame belongs on the
            // inner (callee) side of the boundary.
            if ($this->isPhpVmFunction($symbol_name) && $php_frame_index < $php_frame_count) {
                $merged[] = MergedCallFrame::fromPhp($phpTrace->call_frames[$php_frame_index]);
                $php_frame_index++;
            }

            // Add native frame
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
        // Exact match for known functions
        if (in_array($symbolName, self::PHP_VM_FUNCTIONS, true)) {
            return true;
        }

        // Pattern match for ZEND_DO_*CALL* handler variants
        if (str_starts_with($symbolName, 'ZEND_DO_') && str_contains($symbolName, 'CALL')) {
            return true;
        }

        return false;
    }
}
