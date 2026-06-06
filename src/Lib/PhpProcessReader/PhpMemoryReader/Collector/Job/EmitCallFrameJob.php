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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\Job;

use Reli\Lib\PhpInternals\Types\Zend\ZendExecuteData;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\CallFrameHeaderMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\CallFrameContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\CallFrameVariableTableContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;

/**
 * Emit a single call frame node and push jobs for local variables and $this.
 * Replaces collectCallFrame.
 */
final class EmitCallFrameJob implements CollectorJob
{
    public function __construct(
        private ZendExecuteData $execute_data,
        private ?int $parent_node_id,
        private string $link_name,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $call_frame_context = self::collectCallFrameHeader($this->execute_data, $ctx);

        // Emit the call frame
        $frame_node_id = $ctx->emitNode($call_frame_context, $this->parent_node_id, $this->link_name);
        $parent = $frame_node_id >= 0 ? $frame_node_id : null;

        // Weak (non-tree) edge from this frame to the VM stack arena
        // it lives in. The arena tree is emitted up-front by
        // MemoryLocationsCollector::collectAll() so the lookup table
        // is already populated by the time any EmitCallFrameJob runs
        // (including the post-loop memory_limit_call_frames recovery).
        if ($frame_node_id >= 0 && $ctx->vm_stack_arena_lookup !== []) {
            $frame_addr = $this->execute_data->getPointer()->address;
            foreach ($ctx->vm_stack_arena_lookup as [$arena_begin, $arena_end, $arena_node_id]) {
                if ($frame_addr >= $arena_begin && $frame_addr < $arena_end) {
                    $ctx->sink->emitReference(
                        $arena_node_id,
                        $frame_node_id,
                        'vm_stack_arena',
                        EdgeStrength::Weak,
                    );
                    break;
                }
            }
        }

        // Push jobs for child elements (reverse order for DFS)

        // Extra named params
        if (
            $this->execute_data->hasExtraNamedParams($ctx->zend_type_reader)
            and !is_null($this->execute_data->extra_named_params)
        ) {
            $queue->push(new EmitArrayJob(
                $this->execute_data->extra_named_params,
                $parent,
                'extra_named_params',
            ));
        }

        // Symbol table
        if (
            $this->execute_data->hasSymbolTable($ctx->zend_type_reader)
            and !is_null($this->execute_data->symbol_table)
        ) {
            $queue->push(new EmitArrayJob(
                $this->execute_data->symbol_table,
                $parent,
                'symbol_table',
            ));
        }

        // Local variables — emit the variable table node, then push iterator job
        $local_variables_iterator = $this->execute_data->getVariables(
            $ctx->dereferencer,
            $ctx->zend_type_reader,
        );
        $has_variables = false;
        foreach ($local_variables_iterator as $_name => $_value) {
            $has_variables = true;
            break;
        }
        if ($has_variables) {
            $variable_table_context = new CallFrameVariableTableContext();
            $var_table_node_id = $ctx->emitNode(
                $variable_table_context,
                $parent,
                'local_variables',
            );
            $var_parent = $var_table_node_id >= 0 ? $var_table_node_id : $parent;
            $queue->push(new CallFrameVariableIteratorJob(
                $this->execute_data,
                $var_parent,
            ));
        }

        // $this
        if ($this->execute_data->hasThis()) {
            $queue->push(new ResolveZvalJob(
                $this->execute_data->This,
                $parent,
                'this',
            ));
        }

        // Closure-object back-reference (see collectCallFrameInline for the
        // detailed why; mirrored here so active frames are covered too).
        $closure_addr = $this->execute_data->getClosureObjectAddress($ctx->zend_type_reader);
        if ($closure_addr !== null) {
            try {
                $closure_pointer = new \Reli\Lib\Process\Pointer\Pointer(
                    \Reli\Lib\PhpInternals\Types\Zend\ZendObject::class,
                    $closure_addr,
                    $ctx->zend_type_reader->sizeOf('zend_object'),
                );
                $queue->push(new EmitObjectJob(
                    $closure_pointer,
                    $parent,
                    'closure',
                ));
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Create a CallFrameContext with header info but without children.
     * Used by both the job and inline collection (for generators/fibers).
     */
    public static function collectCallFrameHeader(
        ZendExecuteData $execute_data,
        CollectorContext $ctx,
    ): CallFrameContext {
        $function_name = $execute_data->getFullyQualifiedFunctionName(
            $ctx->dereferencer,
            $ctx->zend_type_reader,
        );

        $lineno = -1;
        $filename = null;
        if ($execute_data->opline !== null and !$execute_data->isInternalCall($ctx->dereferencer)) {
            $opline = $ctx->dereferencer->deref($execute_data->opline);
            $lineno = $opline->lineno;
            if ($execute_data->func !== null) {
                try {
                    $func = $ctx->dereferencer->deref($execute_data->func);
                    $filename = $func->op_array->getFileName($ctx->dereferencer);
                } catch (\Throwable) {
                    $filename = null;
                }
            }
        }

        // Track memory location for the frame header + variable table area.
        try {
            $variable_table_pointer = $execute_data->getVariableTablePointer($ctx->dereferencer);
            $frame_end = $variable_table_pointer->address + $variable_table_pointer->size;
            $frame_size = $frame_end - $execute_data->getPointer()->address;
        } catch (\Throwable) {
            $frame_size = $execute_data->getPointer()->size;
        }
        $header_memory_location = new CallFrameHeaderMemoryLocation(
            $execute_data->getPointer()->address,
            $frame_size,
        );
        $ctx->memory_locations->add($header_memory_location);

        return new CallFrameContext(
            $function_name,
            $lineno,
            $filename,
            $header_memory_location,
        );
    }

    /**
     * Collect a call frame inline (non-job, for use inside generators/fibers).
     *
     * Emits the call frame context immediately (so we have its node_id) and
     * pushes the same child jobs that {@see self::execute()} would push for
     * an active frame: extra_named_params, symbol_table, local variables, $this.
     *
     * This is what catches the locals of suspended Generator/Fiber frames —
     * critical for Phpactor-style workloads where in-flight Amp Coroutines
     * hold parser/reflection working-set memory (token arrays, etc.) in their
     * frame locals, none of which is reachable through any other PHP root.
     */
    public static function collectCallFrameInline(
        ZendExecuteData $execute_data,
        CollectorContext $ctx,
        JobQueue $queue,
        ?int $parent_node_id,
        string $link_name,
    ): CallFrameContext {
        $call_frame_context = self::collectCallFrameHeader($execute_data, $ctx);

        $frame_node_id = $ctx->emitNode($call_frame_context, $parent_node_id, $link_name);
        $parent = $frame_node_id >= 0 ? $frame_node_id : null;

        // Extra named params
        if (
            $execute_data->hasExtraNamedParams($ctx->zend_type_reader)
            and !is_null($execute_data->extra_named_params)
        ) {
            $queue->push(new EmitArrayJob(
                $execute_data->extra_named_params,
                $parent,
                'extra_named_params',
            ));
        }

        // Symbol table
        if (
            $execute_data->hasSymbolTable($ctx->zend_type_reader)
            and !is_null($execute_data->symbol_table)
        ) {
            $queue->push(new EmitArrayJob(
                $execute_data->symbol_table,
                $parent,
                'symbol_table',
            ));
        }

        // Local variables — emit the variable table node, then push iterator job
        $local_variables_iterator = $execute_data->getVariables(
            $ctx->dereferencer,
            $ctx->zend_type_reader,
        );
        $has_variables = false;
        foreach ($local_variables_iterator as $_name => $_value) {
            $has_variables = true;
            break;
        }
        if ($has_variables) {
            $variable_table_context = new \Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\CallFrameVariableTableContext();
            $var_table_node_id = $ctx->emitNode(
                $variable_table_context,
                $parent,
                'local_variables',
            );
            $var_parent = $var_table_node_id >= 0 ? $var_table_node_id : $parent;
            $queue->push(new CallFrameVariableIteratorJob(
                $execute_data,
                $var_parent,
            ));
        }

        // $this
        if ($execute_data->hasThis()) {
            $queue->push(new ResolveZvalJob(
                $execute_data->This,
                $parent,
                'this',
            ));
        }

        // Closure-object back-reference: a closure-invocation frame carries
        // the ZEND_CALL_CLOSURE flag in This.u1.type_info, and PHP recovers
        // the closure object pointer from the func pointer via the
        // ZEND_CLOSURE_OBJECT(func) macro. This is how PHP keeps the closure
        // alive across the call frame *and* across Generator/Fiber suspension
        // — the captured frame retains the flag and refcount=1 is maintained
        // without any IS_OBJECT zval ever referencing the closure. Without
        // this synthetic edge the closure body of every Amp\call() Coroutine
        // (and similar suspended-closure patterns) appears as "no in-heap
        // incoming refs" / orphan from objects_store.
        $closure_addr = $execute_data->getClosureObjectAddress($ctx->zend_type_reader);
        if ($closure_addr !== null) {
            try {
                $closure_pointer = new \Reli\Lib\Process\Pointer\Pointer(
                    \Reli\Lib\PhpInternals\Types\Zend\ZendObject::class,
                    $closure_addr,
                    $ctx->zend_type_reader->sizeOf('zend_object'),
                );
                $queue->push(new EmitObjectJob(
                    $closure_pointer,
                    $parent,
                    'closure',
                ));
            } catch (\Throwable) {
            }
        }

        return $call_frame_context;
    }
}
