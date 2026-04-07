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

use Reli\Lib\PhpInternals\Types\C\PointerArray;
use Reli\Lib\PhpInternals\Types\C\RawInt64;
use Reli\Lib\PhpInternals\Types\Zend\ZendFiber;
use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpInternals\Types\Zend\ZendVmStack;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\VmStackMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\CallFramesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\FiberContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Collect fiber internal data (call frames + VM stack).
 * Replaces collectFiber.
 */
final class EmitFiberJob implements CollectorJob
{
    private ZendFiber $zend_fiber;

    public function __construct(
        ZendObject $object,
        private ?int $object_node_id,
        CollectorContext $ctx,
    ) {
        $this->zend_fiber = $ctx->dereferencer->deref(
            ZendFiber::getPointerFromZendObjectPointer(
                $object->getPointer(),
                $ctx->zend_type_reader,
            ),
        );
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $fiber_context = new FiberContext();

        try {
            if ($this->zend_fiber->execute_data !== null) {
                $execute_data = $ctx->dereferencer->deref($this->zend_fiber->execute_data);
                $call_frames_context = new CallFramesContext();
                foreach ($execute_data->iterateStackChain($ctx->dereferencer) as $key => $frame) {
                    $call_frame_context = EmitCallFrameJob::collectCallFrameInline(
                        $frame,
                        $ctx,
                    );
                    $call_frames_context->add((string)$key, $call_frame_context);
                }
                $fiber_context->add('call_frames', $call_frames_context);
            }
        } catch (\Throwable) {
        }

        // Collect VM stack
        try {
            if (
                !$ctx->zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V82)
                and $this->zend_fiber->vm_stack !== null
                and $ctx->fiber_vm_stack_memory_locations !== null
            ) {
                $vm_stack_current = $ctx->dereferencer->deref($this->zend_fiber->vm_stack);
                foreach ($vm_stack_current->iterateStackChain($ctx->dereferencer) as $vm_stack) {
                    $ctx->fiber_vm_stack_memory_locations->add(
                        VmStackMemoryLocation::fromZendVmStack($vm_stack),
                    );
                }
            } elseif (
                !$ctx->zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V81)
                and $this->zend_fiber->execute_data !== null
                and $ctx->fiber_vm_stack_memory_locations !== null
                and $ctx->chunk_memory_locations !== null
            ) {
                $this->scanFiberCStackForVmStack($ctx);
            }
        } catch (\Throwable) {
        }

        $ctx->emitNode($fiber_context, $this->object_node_id, 'fiber');
    }

    private function scanFiberCStackForVmStack(CollectorContext $ctx): void
    {
        assert($this->zend_fiber->execute_data !== null);
        assert($ctx->fiber_vm_stack_memory_locations !== null);
        assert($ctx->chunk_memory_locations !== null);

        $context_stack = $this->zend_fiber->context_stack;
        if ($context_stack === null) {
            return;
        }
        $fiber_stack = $ctx->dereferencer->deref($context_stack);
        if ($fiber_stack->pointer === 0 || $fiber_stack->size === 0) {
            return;
        }

        $usable_size = $fiber_stack->size;
        if ($usable_size <= 0) {
            return;
        }

        $scan_size = min($usable_size, 256 * 1024);
        $scan_start = $fiber_stack->pointer + $usable_size - $scan_size;

        $c_stack_pointer = new Pointer(
            PointerArray::class,
            $scan_start,
            $scan_size,
        );
        $c_stack = $ctx->dereferencer->deref($c_stack_pointer);
        $execute_data_address = $this->zend_fiber->execute_data->address;
        $vm_stack_size = $ctx->zend_type_reader->sizeOf('struct _zend_vm_stack');

        foreach ($c_stack->getReverseIteratorAsInt() as $value) {
            if ($value === 0) {
                continue;
            }

            $candidate_location = new \Reli\Lib\Process\MemoryLocation($value, 1);
            if (!$ctx->chunk_memory_locations->contains($candidate_location)) {
                continue;
            }

            try {
                $raw_top = $ctx->dereferencer->deref(new Pointer(RawInt64::class, $value, 8))->value;
                $raw_end = $ctx->dereferencer->deref(new Pointer(RawInt64::class, $value + 8, 8))->value;
            } catch (\Throwable) {
                continue;
            }

            if ($raw_top === 0 || $raw_end === 0 || $raw_top >= $raw_end || $raw_top <= $value) {
                continue;
            }
            $top_location = new \Reli\Lib\Process\MemoryLocation($raw_top, 1);
            if (!$ctx->chunk_memory_locations->contains($top_location)) {
                continue;
            }

            if ($execute_data_address < $raw_top || $execute_data_address >= $raw_end) {
                continue;
            }

            try {
                $vm_stack_pointer = new Pointer(ZendVmStack::class, $value, $vm_stack_size);
                $vm_stack_candidate = $ctx->dereferencer->deref($vm_stack_pointer);
                foreach ($vm_stack_candidate->iterateStackChain($ctx->dereferencer) as $vm_stack) {
                    $ctx->fiber_vm_stack_memory_locations->add(
                        VmStackMemoryLocation::fromZendVmStack($vm_stack),
                    );
                }
                return;
            } catch (\Throwable) {
                continue;
            }
        }
    }
}
