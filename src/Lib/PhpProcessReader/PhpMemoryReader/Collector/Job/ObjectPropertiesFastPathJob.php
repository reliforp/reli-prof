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
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpInternals\Types\Zend\ZendPropertyInfo;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Fast-path object properties iterator. Reads property zvals directly
 * from the properties_table inline array via FastPathReader.
 *
 * Property name resolution is done via a cached property map built
 * from the class entry on first access per ce_addr.
 */
final class ObjectPropertiesFastPathJob implements CollectorJob
{
    private int $index = 0;

    /**
     * Cached property map: slot_index => property_name.
     * Built once per ce_addr via FFI, then reused.
     *
     * @var array<int, string>|null
     */
    private ?array $property_map = null;

    /** @var array<int, array<int, string>> static cache: ce_addr => slot map */
    private static array $property_map_cache = [];

    public function __construct(
        private FastPathReader $fp,
        private int $props_base_addr,
        private int $property_count,
        private int $ce_addr,
        private ?int $properties_node_id,
        private CollectorContext $ctx,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        if ($this->index >= $this->property_count) {
            return;
        }

        // Build property name map on first call
        if ($this->property_map === null) {
            $this->property_map = $this->getPropertyMap($ctx);
        }

        $fp = $this->fp;
        $i = $this->index;
        $this->index++;

        $zval_size = $fp->zvalSize();
        $zval_addr = $this->props_base_addr + $i * $zval_size;

        $type = $fp->zvalType($zval_addr);
        if ($type === null || $type === ZvalType::IS_UNDEF) {
            if ($this->index < $this->property_count) {
                $queue->push($this);
            }
            return;
        }

        $name = $this->property_map[$i] ?? "property_{$i}";

        // Advance and re-push for next property
        if ($this->index < $this->property_count) {
            $queue->push($this);
        }

        // Dispatch value (same pattern as ArrayElementsIteratorFastPathJob)
        $value_ptr = $fp->zvalValuePtr($zval_addr);
        if ($value_ptr === null) {
            return;
        }

        switch ($type) {
            case ZvalType::IS_ARRAY:
                if ($value_ptr === 0) {
                    return;
                }
                $queue->push(new EmitArrayJob(
                    new Pointer(
                        \Reli\Lib\PhpInternals\Types\Zend\ZendArray::class,
                        $value_ptr,
                        $ctx->zend_type_reader->sizeOf('zend_array'),
                    ),
                    $this->properties_node_id,
                    $name,
                ));
                return;

            case ZvalType::IS_OBJECT:
                if ($value_ptr === 0) {
                    return;
                }
                $queue->push(new EmitObjectJob(
                    new Pointer(
                        \Reli\Lib\PhpInternals\Types\Zend\ZendObject::class,
                        $value_ptr,
                        $ctx->zend_type_reader->sizeOf('zend_object'),
                    ),
                    $this->properties_node_id,
                    $name,
                ));
                return;

            case ZvalType::IS_STRING:
                if ($value_ptr === 0) {
                    return;
                }
                $queue->push(new EmitStringJob(
                    new Pointer(
                        \Reli\Lib\PhpInternals\Types\Zend\ZendString::class,
                        $value_ptr,
                        $ctx->zend_type_reader->sizeOf('zend_string'),
                    ),
                    $this->properties_node_id,
                    $name,
                ));
                return;

            case ZvalType::IS_REFERENCE:
                if ($value_ptr === 0) {
                    return;
                }
                $queue->push(new EmitPhpReferenceJob(
                    new Pointer(
                        \Reli\Lib\PhpInternals\Types\Zend\ZendReference::class,
                        $value_ptr,
                        $ctx->zend_type_reader->sizeOf('zend_reference'),
                    ),
                    $this->properties_node_id,
                    $name,
                ));
                return;

            case ZvalType::IS_RESOURCE:
                if ($value_ptr === 0) {
                    return;
                }
                $queue->push(new EmitResourceJob(
                    new Pointer(
                        \Reli\Lib\PhpInternals\Types\Zend\ZendResource::class,
                        $value_ptr,
                        $ctx->zend_type_reader->sizeOf('zend_resource'),
                    ),
                    $this->properties_node_id,
                    $name,
                ));
                return;

            case ZvalType::IS_INDIRECT:
                if ($value_ptr === 0) {
                    return;
                }
                $inner = $ctx->dereferencer->deref(
                    new Pointer(Zval::class, $value_ptr, $ctx->zend_type_reader->sizeOf('zval'))
                );
                $queue->push(new ResolveZvalJob($inner, $this->properties_node_id, $name));
                return;

            case ZvalType::IS_TRUE:
            case ZvalType::IS_FALSE:
            case ZvalType::IS_LONG:
            case ZvalType::IS_DOUBLE:
            case ZvalType::IS_NULL:
                $scalar = match ($type) {
                    ZvalType::IS_TRUE => new \Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ScalarValueContext(true),
                    ZvalType::IS_FALSE => new \Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ScalarValueContext(false),
                    ZvalType::IS_LONG => new \Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ScalarValueContext(
                        $this->fp->zvalValueLval($zval_addr) ?? 0
                    ),
                    ZvalType::IS_DOUBLE => new \Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ScalarValueContext(0.0),
                    ZvalType::IS_NULL => new \Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ScalarValueContext(null),
                };
                if ($this->properties_node_id !== null) {
                    $ctx->emitNode($scalar, $this->properties_node_id, $name);
                }
                return;
        }
    }

    /**
     * Build a slot_index => property_name map for this class.
     * Uses FFI to deref the class entry and walk properties_info.
     * Result is cached per ce_addr.
     *
     * @return array<int, string>
     */
    private function getPropertyMap(CollectorContext $ctx): array
    {
        if (isset(self::$property_map_cache[$this->ce_addr])) {
            return self::$property_map_cache[$this->ce_addr];
        }

        $map = [];
        try {
            $ce_pointer = new Pointer(
                \Reli\Lib\PhpInternals\Types\Zend\ZendClassEntry::class,
                $this->ce_addr,
                $ctx->zend_type_reader->sizeOf('zend_class_entry'),
            );
            $ce = $ctx->dereferencer->deref($ce_pointer);
            [$table_offset,] = $ctx->zend_type_reader->getOffsetAndSizeOfMember('zend_object', 'properties_table');
            $is_74_plus = !$ctx->zend_type_reader->isPhpVersionLowerThan(
                \Reli\Lib\PhpInternals\ZendTypeReader::V74,
            );

            foreach ($ce->properties_info->getItemIterator($ctx->dereferencer) as $name => $item) {
                $pi_pointer = $item->value->getAsPointer(
                    ZendPropertyInfo::class,
                    $ctx->zend_type_reader->sizeOf(ZendPropertyInfo::getCTypeName()),
                );
                $pi = $ctx->dereferencer->deref($pi_pointer);
                if ($pi->isStatic($is_74_plus)) {
                    continue;
                }
                $slot = (int)(($pi->offset - $table_offset) / 16);
                $map[$slot] = (string)$name;
            }
        } catch (\Throwable) {
            // Fall back to index-based names
        }

        self::$property_map_cache[$this->ce_addr] = $map;
        return $map;
    }
}
