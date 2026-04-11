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

use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectHandlersMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectPropertiesContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Emit a ZendObject node and push child jobs for properties and special types.
 * Replaces collectZendObjectPointer + collectZendObject.
 */
final class EmitObjectJob implements CollectorJob
{
    /** @param Pointer<ZendObject> $pointer */
    public function __construct(
        private Pointer $pointer,
        private ?int $parent_node_id,
        private string $link_name,
        private EdgeStrength $edge_strength = EdgeStrength::Strong,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $address = $this->pointer->address;

        // Dedup
        if ($ctx->memory_locations->has($address)) {
            $existing_node_id = $ctx->address_map[$address] ?? null;
            if ($existing_node_id !== null) {
                if ($this->parent_node_id !== null) {
                    $ctx->sink->emitReference(
                        $existing_node_id,
                        $this->parent_node_id,
                        $this->link_name,
                        $this->edge_strength,
                    );
                }
                return;
            }
            $cached = $ctx->context_pools->object_context_pool->getContextByAddress($address);
            if ($cached !== null) {
                $node_id = $ctx->emitNode($cached, $this->parent_node_id, $this->link_name, $this->edge_strength);
                if ($node_id >= 0) {
                    $ctx->address_map[$address] = $node_id;
                }
                return;
            }
        }

        $object = $ctx->dereferencer->deref($this->pointer);
        $this->processObject($object, $ctx, $queue);
    }

    private function processObject(ZendObject $object, CollectorContext $ctx, JobQueue $queue): void
    {
        $object_location = ZendObjectMemoryLocation::fromZendObject(
            $object,
            $ctx->dereferencer,
            $ctx->zend_type_reader,
        );
        $object_handlers_memory_location = ZendObjectHandlersMemoryLocation::fromZendObject(
            $object,
            $ctx->zend_type_reader,
        );
        $ctx->memory_locations->add($object_location);
        $zend_object_address = $object->getPointer()->address;
        if ($object_location->address !== $zend_object_address) {
            $ctx->memory_locations->addAlias($zend_object_address, $object_location);
        }
        $ctx->memory_locations->add($object_handlers_memory_location);

        $object_context = $ctx->context_pools
            ->object_context_pool->getContextForLocation($object_location);
        $object_handlers_context = $ctx->context_pools
            ->object_context_pool
            ->getHandlersContextForLocation($object_handlers_memory_location);
        $object_context->add('object_handlers', $object_handlers_context);

        // Create properties context and add to object
        $object_properties_context = new ObjectPropertiesContext();
        if ($object->ce !== null) {
            try {
                $ce = $ctx->dereferencer->deref($object->ce);
                $object_properties_context->setCount($ce->default_properties_count);
            } catch (\Throwable) {
            }
        }
        $object_context->add('object_properties', $object_properties_context);

        // PDO/PDOStatement handling (must be before emit so locations are included)
        $this->handlePdoClasses(
            $object,
            $object_location->class_name,
            $ctx,
            $object_context,
        );

        // Emit the object node
        $object_node_id = $ctx->emitNode(
            $object_context,
            $this->parent_node_id,
            $this->link_name,
            $this->edge_strength,
        );
        if ($object_node_id >= 0) {
            $ctx->address_map[$object_location->address] = $object_node_id;
            if ($object_location->address !== $zend_object_address) {
                $ctx->address_map[$zend_object_address] = $object_node_id;
            }
        }

        // Get properties parent node_id. Used to read from $ctx->memo
        // (a WeakMap populated by the analyzer); after the analyzer's
        // memo moved to a $context->memo_node_id property in 1ae56c4f
        // the WeakMap stayed empty here, so this lookup silently
        // returned null and the deferred property edges were never
        // wired up to the right parent — which is the corruption that
        // was producing wrong Root Blame Allocation and broken
        // bottleneck_path retained sizes.
        /** @psalm-suppress UndefinedPropertyFetch */
        $properties_node_id = isset($object_properties_context->memo_node_id)
            ? $object_properties_context->memo_node_id
            : null;
        if ($properties_node_id !== null) {
            $properties_node_id = $properties_node_id < 0 ? -$properties_node_id - 1 : $properties_node_id;
        }

        // Push child jobs in reverse order for DFS (last pushed = first processed)

        // Special class handling - push these first (processed last)
        assert(!is_null($object->ce));
        $class_entry = $ctx->dereferencer->deref($object->ce);
        $class_name = $class_entry->getClassName($ctx->dereferencer);
        $obj_node_id = $object_node_id >= 0 ? $object_node_id : null;

        $this->pushSpecialClassJobs(
            $object,
            $class_name,
            $obj_node_id,
            $ctx,
            $queue,
        );

        // Dynamic properties
        if (
            !is_null($object->properties)
            and !$object->isEnum($ctx->dereferencer)
        ) {
            $queue->push(new EmitArrayDirectJob(
                $ctx->dereferencer->deref($object->properties),
                $obj_node_id,
                'dynamic_properties',
            ));
        }

        // Properties iterator job (processed first = DFS into properties)
        $queue->push(new ObjectPropertiesIteratorJob(
            $object,
            $properties_node_id,
        ));
    }

    private function pushSpecialClassJobs(
        ZendObject $object,
        string $class_name,
        ?int $object_node_id,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        if (
            $class_name === 'WeakMap'
            and !$ctx->zend_type_reader->isPhpVersionLowerThan(\Reli\Lib\PhpInternals\ZendTypeReader::V80)
        ) {
            try {
                $queue->push(new EmitWeakMapJob(
                    $object,
                    $object_node_id,
                    $ctx,
                ));
            } catch (\Throwable) {
            }
        }

        if (
            $class_name === 'WeakReference'
            and !$ctx->zend_type_reader->isPhpVersionLowerThan(\Reli\Lib\PhpInternals\ZendTypeReader::V74)
        ) {
            try {
                $queue->push(new EmitWeakReferenceJob(
                    $object,
                    $object_node_id,
                    $ctx,
                ));
            } catch (\Throwable) {
            }
        }

        if (
            $class_name === 'Fiber'
            and !$ctx->zend_type_reader->isPhpVersionLowerThan(\Reli\Lib\PhpInternals\ZendTypeReader::V81)
        ) {
            try {
                $queue->push(new EmitFiberJob(
                    $object,
                    $object_node_id,
                    $ctx,
                ));
            } catch (\Throwable) {
            }
        }

        if ($class_name === 'Generator') {
            try {
                $queue->push(new EmitGeneratorJob(
                    $object,
                    $object_node_id,
                    $ctx,
                ));
            } catch (\Throwable) {
            }
        }

        if (
            $class_name === 'Closure'
            and !$ctx->zend_type_reader->isPhpVersionLowerThan(\Reli\Lib\PhpInternals\ZendTypeReader::V71)
        ) {
            try {
                $queue->push(new EmitClosureJob(
                    $object,
                    $object_node_id,
                    $ctx,
                ));
            } catch (\Throwable) {
            }
        }
    }

    private function handlePdoClasses(
        ZendObject $object,
        string $class_name,
        CollectorContext $ctx,
        \Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectContext $object_context,
    ): void {
        if ($class_name === \PDO::class) {
            try {
                PdoHelper::collectPdoDbhObject(
                    $object,
                    $ctx,
                    $object_context,
                );
            } catch (\Throwable) {
            }
        }

        if ($class_name === \PDOStatement::class) {
            try {
                PdoHelper::collectPdoStmt(
                    $object,
                    $ctx,
                    $object_context,
                );
            } catch (\Throwable) {
            }
        }
    }
}
