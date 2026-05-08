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
use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringSlotTailMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayElementContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Fast-path array element iterator. Reads bucket/zval data directly
 * from string buffers via FastPathReader, avoiding FFI wrapper objects
 * and generator overhead.
 *
 * Processes one element per execute() call and re-pushes itself
 * for DFS queue discipline, same as ArrayElementsIteratorJob.
 */
final class ArrayElementsIteratorFastPathJob implements CollectorJob
{
    private int $index = 0;

    public function __construct(
        private FastPathReader $fp,
        private int $ar_data_address,
        private int $n_num_used,
        private bool $is_packed,
        private ?int $elements_node_id,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        if ($this->index >= $this->n_num_used) {
            return;
        }

        $fp = $this->fp;
        $i = $this->index;
        $this->index++;

        if ($this->is_packed) {
            // Packed array: element stride depends on PHP version.
            // PHP < 8.2: Bucket[] layout (32 bytes), PHP >= 8.2: zval[] (16 bytes)
            $zval_addr = $this->ar_data_address + $i * $fp->packedElementSize();
            $type = $fp->zvalType($zval_addr);
            if ($type === null || $type === ZvalType::IS_UNDEF) {
                if ($this->index < $this->n_num_used) {
                    $queue->push($this);
                }
                return;
            }

            $element_context = new ArrayElementContext();
            $key_string = (string)$i;

            $element_node_id = $ctx->emitNode(
                $element_context,
                $this->elements_node_id,
                $key_string,
            );
            $value_parent = $element_node_id >= 0 ? $element_node_id : null;

            if ($this->index < $this->n_num_used) {
                $queue->push($this);
            }

            // Push value resolution — use fast path Zval dispatch
            $value_ptr = $fp->zvalValuePtr($zval_addr);
            if ($value_ptr !== null) {
                $this->pushValueJob($type, $value_ptr, $zval_addr, $value_parent, $ctx, $queue);
            }
        } else {
            // Hash array: Bucket[nNumUsed] at arData
            $bucket_addr = $this->ar_data_address + $i * $fp->bucketSize();
            $val_type = $fp->bucketValType($bucket_addr);
            if ($val_type === null || $val_type === ZvalType::IS_UNDEF) {
                if ($this->index < $this->n_num_used) {
                    $queue->push($this);
                }
                return;
            }

            $element_context = new ArrayElementContext();
            $key_ptr = $fp->bucketKeyPtr($bucket_addr);

            if ($key_ptr !== null && $key_ptr !== 0) {
                // String key
                $key_address = $key_ptr;
                if (!$ctx->memory_locations->has($key_address)) {
                    $key_len = $fp->stringLen($key_address);
                    $key_h = $fp->stringH($key_address);
                    $key_refcount = $fp->stringRefcount($key_address);
                    $key_type_info = $fp->stringTypeInfo($key_address);
                    if ($key_len !== null && $key_refcount !== null && $key_type_info !== null) {
                        $key_val = $fp->stringVal($key_address, $key_len) ?? '';
                        $memory_location = new ZendStringMemoryLocation(
                            $key_address,
                            $fp->stringHeaderSize() + $key_len,
                            $key_refcount,
                            $key_type_info,
                            $key_val,
                            $key_h ?? 0,
                        );
                        $ctx->memory_locations->add($memory_location);
                        $slot_tail = ZendStringSlotTailMemoryLocation::tryFromStringInChunks(
                            $memory_location,
                            $ctx->chunk_memory_locations,
                        );
                        if ($slot_tail !== null) {
                            $ctx->memory_locations->add($slot_tail);
                        }
                        $key_context = $ctx->context_pools
                            ->string_context_pool
                            ->getContextForLocation($memory_location, $slot_tail);
                        $element_context->add('key', $key_context);
                        $key_string = $key_val;
                    } else {
                        $key_string = (string)$i;
                    }
                } else {
                    $existing_node_id = $ctx->address_map[$key_address] ?? null;
                    if ($existing_node_id !== null) {
                        $element_context->add('key', $existing_node_id);
                    } else {
                        $cached = $ctx->context_pools->string_context_pool
                            ->getContextByAddress($key_address);
                        if ($cached !== null) {
                            $element_context->add('key', $cached);
                        }
                    }
                    // Read string value for key name
                    $key_len = $fp->stringLen($key_address);
                    $key_string = ($key_len !== null)
                        ? ($fp->stringVal($key_address, $key_len) ?? (string)$i)
                        : (string)$i;
                }
            } else {
                // Integer key
                $key_string = (string)$i;
            }

            $element_node_id = $ctx->emitNode(
                $element_context,
                $this->elements_node_id,
                $key_string,
            );
            $value_parent = $element_node_id >= 0 ? $element_node_id : null;

            if ($this->index < $this->n_num_used) {
                $queue->push($this);
            }

            $val_value_ptr = $fp->bucketValValuePtr($bucket_addr);
            if ($val_value_ptr !== null) {
                // The zval is embedded in the bucket at offset 0
                $zval_addr = $bucket_addr; // bucket.val is at offset 0
                $this->pushValueJob($val_type, $val_value_ptr, $zval_addr, $value_parent, $ctx, $queue);
            }
        }
    }

    private function pushValueJob(
        int $type,
        int $value_ptr,
        int $zval_addr,
        ?int $parent_node_id,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
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
                    $parent_node_id,
                    'value',
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
                    $parent_node_id,
                    'value',
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
                    $parent_node_id,
                    'value',
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
                    $parent_node_id,
                    'value',
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
                    $parent_node_id,
                    'value',
                ));
                return;

            case ZvalType::IS_INDIRECT:
                if ($value_ptr === 0) {
                    return;
                }
                // Indirect: value_ptr points to another zval
                $inner = $ctx->dereferencer->deref(
                    new Pointer(Zval::class, $value_ptr, $ctx->zend_type_reader->sizeOf('zval'))
                );
                $queue->push(new ResolveZvalJob($inner, $parent_node_id, 'value'));
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
                if ($parent_node_id !== null) {
                    $ctx->emitNode($scalar, $parent_node_id, 'value');
                }
                return;
        }
    }
}
