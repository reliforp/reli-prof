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

use Reli\Inspector\MemoryDump\FastPath\FastPathReader;
use Reli\Inspector\MemoryDump\FastPath\ZvalType;
use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\FfiAllocationMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectHandlersMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectPropertiesContext;
use Reli\Lib\Process\MemoryLocation;
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

        $fp = $ctx->fast_path;
        if ($fp !== null) {
            $refcount = $fp->objectRefcount($address);
            if ($refcount !== null) {
                $ce_addr = $fp->objectCe($address);
                if ($ce_addr !== null && $ce_addr !== 0) {
                    $ce_info = $fp->resolveClassEntry($ce_addr);
                    if ($ce_info !== null) {
                        $this->processObjectFastPath($fp, $address, $ce_addr, $ce_info, $ctx, $queue);
                        return;
                    }
                }
            }
        }

        $object = $ctx->dereferencer->deref($this->pointer);
        $this->processObject($object, $ctx, $queue);
    }

    /**
     * @param array{class_name: string, default_properties_count: int} $ce_info
     */
    private function processObjectFastPath(
        FastPathReader $fp,
        int $address,
        int $ce_addr,
        array $ce_info,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        $refcount = $fp->objectRefcount($address) ?? 0;
        $type_info = $fp->objectTypeInfo($address) ?? 0;
        $handle = $fp->objectHandle($address) ?? 0;
        $handlers_addr = $fp->objectHandlers($address) ?? 0;

        $class_name = $ce_info['class_name'];
        $default_properties_count = $ce_info['default_properties_count'];

        // Compute object memory size (same logic as ZendObject::getMemorySize)
        $base_size = $this->pointer->size;
        $obj_address = $address;
        $size = $base_size + ($default_properties_count - 1) * 16;

        // FFI\CData: adjust size to include zend_ffi_cdata fields
        // (type ptr + ptr + ptr_holder + flags = 3*8 + 4 = 28, padded to 32)
        if ($class_name === 'FFI\\CData') {
            $size = $base_size + 32; // sizeof(zend_ffi_cdata) - sizeof(zend_object) + sizeof(zend_object)
        }

        // Special classes need std_offset adjustment — fall back to FFI
        if (
            $class_name === \Fiber::class
            || $class_name === \Closure::class
            || $class_name === \Generator::class
            || $class_name === \WeakReference::class
            || $class_name === \WeakMap::class
            || $class_name === \PDO::class
            || $class_name === \PDOStatement::class
            || $class_name === 'SimpleXMLElement'
            || $class_name === 'SimpleXMLIterator'
        ) {
            $object = $ctx->dereferencer->deref($this->pointer);
            $this->processObject($object, $ctx, $queue);
            return;
        }

        $object_location = new ZendObjectMemoryLocation(
            $obj_address,
            $size,
            $refcount,
            $type_info,
            $class_name,
        );

        $object_handlers_location = new ZendObjectHandlersMemoryLocation(
            $handlers_addr,
            $ctx->zend_type_reader->sizeOf('zend_object_handlers'),
        );

        $ctx->memory_locations->add($object_location);
        if ($object_location->address !== $address) {
            $ctx->memory_locations->addAlias($address, $object_location);
        }
        $ctx->memory_locations->add($object_handlers_location);

        $object_context = $ctx->context_pools
            ->object_context_pool->getContextForLocation($object_location);
        $object_handlers_context = $ctx->context_pools
            ->object_context_pool
            ->getHandlersContextForLocation($object_handlers_location);
        $object_context->add('object_handlers', $object_handlers_context);

        $object_properties_context = new ObjectPropertiesContext();
        $object_properties_context->setCount($default_properties_count);
        $object_context->add('object_properties', $object_properties_context);

        $object_node_id = $ctx->emitNode(
            $object_context,
            $this->parent_node_id,
            $this->link_name,
            $this->edge_strength,
        );
        if ($object_node_id >= 0) {
            $ctx->address_map[$object_location->address] = $object_node_id;
            if ($object_location->address !== $address) {
                $ctx->address_map[$address] = $object_node_id;
            }
        }

        $raw_properties = $object_properties_context->getMemoNodeId();
        $properties_node_id = $raw_properties === null
            ? null
            : ($raw_properties < 0 ? -$raw_properties - 1 : $raw_properties);

        $obj_node_id = $object_node_id >= 0 ? $object_node_id : null;

        // Dynamic properties (hash table pointed to by zend_object.properties)
        $properties_ptr = $fp->objectProperties($address);
        if ($properties_ptr !== null && $properties_ptr !== 0) {
            // Check if this is an enum (enums don't have dynamic properties)
            $is_enum = false;
            try {
                $ce_pointer = new Pointer(
                    \Reli\Lib\PhpInternals\Types\Zend\ZendClassEntry::class,
                    $ce_addr,
                    $ctx->zend_type_reader->sizeOf('zend_class_entry'),
                );
                $ce_obj = $ctx->dereferencer->deref($ce_pointer);
                $is_enum = $ce_obj->isEnum();
            } catch (\Throwable) {
            }
            if (!$is_enum) {
                $queue->push(new EmitArrayJob(
                    new Pointer(
                        \Reli\Lib\PhpInternals\Types\Zend\ZendArray::class,
                        $properties_ptr,
                        $ctx->zend_type_reader->sizeOf('zend_array'),
                    ),
                    $obj_node_id,
                    'dynamic_properties',
                ));
            }
        }

        // Properties iterator — fast path using zval array at properties_table offset
        if ($default_properties_count > 0) {
            [$table_offset,] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('zend_object', 'properties_table');
            $props_base = $address + $table_offset;
            $queue->push(new ObjectPropertiesFastPathJob(
                $fp,
                $props_base,
                $default_properties_count,
                $ce_addr,
                $properties_node_id,
                $ctx,
            ));
        }

        // FFI\CData: track the emalloc'd backing buffer.
        // zend_ffi_cdata layout after zend_object std:
        //   +0: zend_ffi_type *type    (8 bytes)
        //   +8: void *ptr              (8 bytes)
        //  +16: void *ptr_holder       (8 bytes)
        //  +24: uint32_t flags         (4 bytes)
        // type->size at offset 8 in zend_ffi_type (after kind:u32 + padding).
        if ($class_name === 'FFI\\CData' && $obj_node_id !== null) {
            $this->emitFfiAllocation($address, $obj_node_id, $ctx);
        }
    }

    private function emitFfiAllocation(
        int $obj_address,
        int $parent_node_id,
        CollectorContext $ctx,
    ): void {
        try {
            // zend_ffi_cdata layout after zend_object:
            //   +0: zend_ffi_type *type    (8, low bit is ownership tag)
            //   +8: void *ptr              (8, actual data address)
            //  +16: void *ptr_holder       (8)
            //  +24: uint32_t flags         (4)
            //
            // zend_ffi_flags:
            //   ZEND_FFI_FLAG_CONST      = 1
            //   ZEND_FFI_FLAG_OWNED      = 2
            //   ZEND_FFI_FLAG_PERSISTENT = 4
            $sizeof_zend_object = $ctx->zend_type_reader->sizeOf('zend_object');
            $ffi_fields_base = $obj_address + $sizeof_zend_object;

            $deref = $ctx->dereferencer;
            $i64size = 8;

            // Read flags (uint32 at std_end + 24)
            /** @var \Reli\Lib\PhpInternals\Types\C\RawInt32 $flags_raw */
            $flags_raw = $deref->deref(new Pointer(
                \Reli\Lib\PhpInternals\Types\C\RawInt32::class,
                $ffi_fields_base + 24,
                4,
            ));
            $flags = $flags_raw->value;

            // Only track owned CData (FFI::new). Skip views (FFI::cast/addr).
            if (($flags & 2) === 0) { // ZEND_FFI_FLAG_OWNED
                return;
            }

            // Read ptr (the actual data address, at std_end + 8)
            /** @var \Reli\Lib\PhpInternals\Types\C\RawInt64 $ptr_raw */
            $ptr_raw = $deref->deref(new Pointer(
                \Reli\Lib\PhpInternals\Types\C\RawInt64::class,
                $ffi_fields_base + 8,
                $i64size,
            ));
            $data_ptr = $ptr_raw->value;
            if ($data_ptr === 0) {
                return;
            }

            // Read type pointer (std_end + 0), strip low-bit tag (ZEND_FFI_TYPE_OWNED)
            /** @var \Reli\Lib\PhpInternals\Types\C\RawInt64 $type_raw */
            $type_raw = $deref->deref(new Pointer(
                \Reli\Lib\PhpInternals\Types\C\RawInt64::class,
                $ffi_fields_base,
                $i64size,
            ));
            $type_ptr = $type_raw->value & ~1; // strip tag bit
            if ($type_ptr === 0) {
                return;
            }

            // Read type->size (size_t at offset 8 in zend_ffi_type)
            /** @var \Reli\Lib\PhpInternals\Types\C\RawInt64 $size_raw */
            $size_raw = $deref->deref(new Pointer(
                \Reli\Lib\PhpInternals\Types\C\RawInt64::class,
                $type_ptr + 8,
                $i64size,
            ));
            $alloc_size = $size_raw->value;

            if ($alloc_size <= 0) {
                return;
            }

            $is_persistent = ($flags & 4) !== 0; // ZEND_FFI_FLAG_PERSISTENT

            $location = new FfiAllocationMemoryLocation(
                $data_ptr,
                (int)$alloc_size,
                $is_persistent ? 'FFI\\CData::buffer(persistent)' : 'FFI\\CData::buffer',
            );
            $ctx->memory_locations->add($location);

            $ffi_context = new \Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\FfiAllocationContext($location);
            $ctx->emitNode(
                $ffi_context,
                $parent_node_id,
                'ffi_buffer',
                EdgeStrength::Strong,
            );
        } catch (\Throwable) {
            // Fail silently — FFI introspection is best-effort
        }
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
        // memo moved onto the Context itself in 1ae56c4f the WeakMap
        // stayed empty here and this lookup silently returned null,
        // dropping the deferred property edges. Reading the memo via
        // the interface accessor closes that gap.
        $raw_properties = $object_properties_context->getMemoNodeId();
        $properties_node_id = $raw_properties === null
            ? null
            : ($raw_properties < 0 ? -$raw_properties - 1 : $raw_properties);

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

        if ($class_name === 'SimpleXMLElement' || $class_name === 'SimpleXMLIterator') {
            try {
                $queue->push(new EmitSimpleXMLJob(
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
