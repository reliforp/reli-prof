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
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\CallFramesContext;

/**
 * Emit the call_frames root branch.
 * Replaces collectCallFrames.
 */
final class EmitCallFramesJob implements CollectorJob
{
    public function __construct(
        private ZendExecutorGlobals $eg,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $call_frames_context = new CallFramesContext();

        // Emit the root node
        $root_node_id = $ctx->emitNode($call_frames_context, null, 'call_frames');
        $parent = $root_node_id >= 0 ? $root_node_id : null;

        if (is_null($this->eg->current_execute_data)) {
            return;
        }

        $execute_data = $ctx->dereferencer->deref($this->eg->current_execute_data);

        // Collect all frames as a list, then push them as jobs.
        // iterateStackChain yields leaf first, then its callers down to
        // <main> — so $frames[0] is the innermost frame and
        // $frames[N-1] is the root.
        $frames = [];
        foreach ($execute_data->iterateStackChain($ctx->dereferencer) as $key => $frame) {
            $frames[] = [(string)$key, $frame];
        }

        // Each non-leaf frame's true allocated size is
        // `successor.address - this.address` where `successor` is the
        // frame it called (= the previous index in the chain order =
        // higher VM stack address). The leaf has no successor in the
        // chain; for the active live arena its allocation ends at
        // EG(vm_stack_top), so use that.
        $leaf_successor = $this->eg->vm_stack_top?->address;
        for ($i = count($frames) - 1; $i >= 0; $i--) {
            [$key, $frame] = $frames[$i];
            $successor = $i === 0
                ? $leaf_successor
                : $frames[$i - 1][1]->getPointer()->address;
            $queue->push(new EmitCallFrameJob($frame, $parent, $key, $successor));
        }
    }
}
