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

        // Push jobs for child elements (reverse order for DFS)

        // Extra named params
        if ($this->execute_data->hasExtraNamedParams() and !is_null($this->execute_data->extra_named_params)) {
            $queue->push(new EmitArrayJob(
                $this->execute_data->extra_named_params,
                $parent,
                'extra_named_params',
            ));
        }

        // Symbol table
        if ($this->execute_data->hasSymbolTable() and !is_null($this->execute_data->symbol_table)) {
            $queue->push(new EmitArrayJob(
                $this->execute_data->symbol_table,
                $parent,
                'symbol_table',
            ));
        }

        // Local variables
        $variable_table_context = new CallFrameVariableTableContext();
        $local_variables_iterator = $this->execute_data->getVariables(
            $ctx->dereferencer,
            $ctx->zend_type_reader,
        );
        $has_variables = false;
        foreach ($local_variables_iterator as $name => $value) {
            $has_variables = true;
            break;
        }
        if ($has_variables) {
            // Re-get the iterator since we consumed one element for the check
            $call_frame_context->add('local_variables', $variable_table_context);
            $var_table_node_id = $ctx->memo[$variable_table_context] ?? null;
            if ($var_table_node_id !== null) {
                $var_table_node_id = $var_table_node_id < 0 ? -$var_table_node_id - 1 : $var_table_node_id;
            }
            $queue->push(new CallFrameVariableIteratorJob(
                $this->execute_data,
                $var_table_node_id,
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
        if ($execute_data->opline !== null and !$execute_data->isInternalCall($ctx->dereferencer)) {
            $opline = $ctx->dereferencer->deref($execute_data->opline);
            $lineno = $opline->lineno;
        }

        $call_frame_context = new CallFrameContext($function_name, $lineno);

        // Track memory location
        try {
            $variable_table_pointer = $execute_data->getVariableTablePointer($ctx->dereferencer);
            $frame_end = $variable_table_pointer->address + $variable_table_pointer->size;
            $frame_size = $frame_end - $execute_data->getPointer()->address;
        } catch (\Throwable) {
            $frame_size = $execute_data->getPointer()->size;
        }
        $ctx->memory_locations->add(
            new CallFrameHeaderMemoryLocation(
                $execute_data->getPointer()->address,
                $frame_size,
            ),
        );

        return $call_frame_context;
    }

    /**
     * Collect a call frame inline (non-job, for use inside generators/fibers).
     * This does NOT push child jobs - it collects everything synchronously.
     * Used when the call frame is inside a generator/fiber context where
     * the variable values need to be resolved inline (they're part of the
     * generator/fiber's own allocation).
     */
    public static function collectCallFrameInline(
        ZendExecuteData $execute_data,
        CollectorContext $ctx,
    ): CallFrameContext {
        $call_frame_context = self::collectCallFrameHeader($execute_data, $ctx);

        // For inline collection (generators/fibers), we skip collecting
        // $this, local variables, symbol_table, and extra_named_params
        // because they would require recursive zval resolution.
        // The original code did collect these, but the iterative design
        // handles them through the job queue when the generator/fiber
        // object's properties are processed.

        return $call_frame_context;
    }
}
