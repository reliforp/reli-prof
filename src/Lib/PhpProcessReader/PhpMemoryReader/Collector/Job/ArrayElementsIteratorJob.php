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

use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayElementContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Iterator job that processes one array element per tick.
 * Holds an iterator internally and re-pushes itself until exhausted.
 *
 * Queue size stays O(depth) rather than O(breadth) for large arrays.
 */
final class ArrayElementsIteratorJob implements CollectorJob
{
    /** @var \Iterator|null */
    private ?\Iterator $iterator = null;
    private bool $started = false;

    public function __construct(
        private ZendArray $array,
        private ?int $elements_node_id,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        if (!$this->started) {
            $this->started = true;
            $gen = $this->array->getItemIteratorWithZendStringKeyIfAssoc($ctx->dereferencer);
            if ($gen instanceof \Iterator) {
                $this->iterator = $gen;
            } else {
                // Wrap iterable in a generator to get an Iterator
                $this->iterator = (function () use ($gen): \Generator {
                    yield from $gen;
                })();
            }
            $this->iterator->rewind();
        }

        if ($this->iterator === null || !$this->iterator->valid()) {
            return;
        }

        /** @var int|Pointer $key */
        $key = $this->iterator->key();
        /** @var Zval $zval */
        $zval = $this->iterator->current();

        $element_context = new ArrayElementContext();
        $key_string = '';

        if ($key instanceof Pointer) {
            // String key - collect the ZendString
            /** @var Pointer<ZendString> $key */
            $key_address = $key->address;
            if (!$ctx->memory_locations->has($key_address)) {
                /** @var ZendString $zend_string */
                $zend_string = $ctx->dereferencer->deref($key);
                $memory_location = ZendStringMemoryLocation::fromZendString($zend_string, $ctx->dereferencer);
                $ctx->memory_locations->add($memory_location);
                $key_context = $ctx->context_pools->string_context_pool->getContextForLocation($memory_location);
                $element_context->add('key', $key_context);
                $key_string = $zend_string->toString($ctx->dereferencer);
            } else {
                $existing_node_id = $ctx->address_map[$key_address] ?? null;
                if ($existing_node_id !== null) {
                    $element_context->add('key', $existing_node_id);
                } else {
                    $cached = $ctx->context_pools->string_context_pool->getContextByAddress($key_address);
                    if ($cached !== null) {
                        $element_context->add('key', $cached);
                    }
                }
                /** @var ZendString $zend_string */
                $zend_string = $ctx->dereferencer->deref($key);
                $key_string = $zend_string->toString($ctx->dereferencer);
            }
        } else {
            $key_string = (string)$key;
        }

        // Emit the element context node
        $element_node_id = $ctx->emitNode(
            $element_context,
            $this->elements_node_id,
            $key_string,
        );
        $value_parent_node_id = $element_node_id >= 0 ? $element_node_id : null;

        // Advance the iterator for next time
        $this->iterator->next();

        // For DFS: push continuation first (processed later), then child (processed next)
        if ($this->iterator->valid()) {
            // Re-push self for next element (will be processed after child subtree)
            $queue->push($this);
        }

        // Push the value resolution job (processed next = DFS into subtree)
        $queue->push(new ResolveZvalJob(
            $zval,
            $value_parent_node_id,
            'value',
        ));
    }
}
