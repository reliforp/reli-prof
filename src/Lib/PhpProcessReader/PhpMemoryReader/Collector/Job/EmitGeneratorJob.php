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

use Reli\Lib\PhpInternals\Types\Zend\ZendGenerator;
use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\CallFramesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\GeneratorContext;

/**
 * Collect generator internal data (call frames, value, key, retval).
 * Replaces collectGenerator.
 */
final class EmitGeneratorJob implements CollectorJob
{
    private ZendGenerator $zend_generator;

    public function __construct(
        ZendObject $object,
        private ?int $object_node_id,
        CollectorContext $ctx,
    ) {
        $this->zend_generator = $ctx->dereferencer->deref(
            ZendGenerator::getPointerFromZendObjectPointer(
                $object->getPointer(),
                $ctx->zend_type_reader,
            ),
        );
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $generator_context = new GeneratorContext();

        // Emit the generator context first so we have a node_id to attach
        // call frames (and their locals) under.
        $generator_node_id = $ctx->emitNode($generator_context, $this->object_node_id, 'generator');
        $parent = $generator_node_id >= 0 ? $generator_node_id : null;

        // Collect call frames — emit each inline so its locals walking jobs
        // can be queued with the correct parent.
        try {
            if (
                $this->zend_generator->execute_data !== null
                and !$this->isExecuteDataInsideGeneratorStruct($ctx)
            ) {
                $execute_data = $ctx->dereferencer->deref($this->zend_generator->execute_data);
                $call_frames_context = new CallFramesContext();
                $call_frames_node_id = $ctx->emitNode($call_frames_context, $parent, 'call_frames');
                $cf_parent = $call_frames_node_id >= 0 ? $call_frames_node_id : $parent;
                foreach ($execute_data->iterateStackChain($ctx->dereferencer) as $key => $frame) {
                    EmitCallFrameJob::collectCallFrameInline(
                        $frame,
                        $ctx,
                        $queue,
                        $cf_parent,
                        (string)$key,
                    );
                }
            }
        } catch (\Throwable) {
        }

        // Push jobs to walk the `node` field's raw zend_generator* pointers.
        // These wire `yield from` chains together with refcount management
        // done in C (not via zval), so without an explicit emit here every
        // sub-generator that's only reachable through such a chain ends up
        // tree-owned by objects_store iter despite the parent generator being
        // walker-reachable. EmitObjectJob is fail-soft for bad pointers
        // (caught by the job runner) and dispatches to EmitGeneratorJob
        // recursively when the target's class is Generator.
        try {
            $node_gen_ptrs = $this->zend_generator->getNodeRawGeneratorPointers(
                $ctx->zend_type_reader,
            );
            foreach ($node_gen_ptrs as $idx => $node_gen_ptr) {
                $node_pointer = new \Reli\Lib\Process\Pointer\Pointer(
                    ZendObject::class,
                    $node_gen_ptr,
                    $ctx->zend_type_reader->sizeOf('zend_object'),
                );
                $queue->push(new EmitObjectJob(
                    $node_pointer,
                    $parent,
                    'node[' . $idx . ']',
                ));
            }
        } catch (\Throwable) {
        }

        // Push jobs for value, key, retval (in reverse order for DFS)
        try {
            $queue->push(new ResolveZvalJob($this->zend_generator->retval, $parent, 'retval'));
        } catch (\Throwable) {
        }

        try {
            $queue->push(new ResolveZvalJob($this->zend_generator->key, $parent, 'key'));
        } catch (\Throwable) {
        }

        try {
            $queue->push(new ResolveZvalJob($this->zend_generator->value, $parent, 'value'));
        } catch (\Throwable) {
        }
    }

    private function isExecuteDataInsideGeneratorStruct(CollectorContext $ctx): bool
    {
        assert($this->zend_generator->execute_data !== null);
        $execute_data_address = $this->zend_generator->execute_data->address;
        $generator_address = $this->zend_generator->getPointer()->address;
        $generator_size = $ctx->zend_type_reader->sizeOf('zend_generator');
        return $execute_data_address >= $generator_address
            && $execute_data_address < $generator_address + $generator_size;
    }
}
