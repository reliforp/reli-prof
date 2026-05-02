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
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Iterator job that processes one regular_list bucket per tick (DFS).
 *
 * regular_list is a HashTable<int, zval>, where each zval is IS_RESOURCE
 * pointing at a zend_resource. We push EmitResourceJob directly with
 * EdgeStrength::Weak rather than going through ResolveZvalJob, both to
 * keep edges weak and to skip the cost of zval-type dispatch for entries
 * that are by construction always resources.
 */
final class RegularListBucketIteratorJob implements CollectorJob
{
    private bool $started = false;
    private ?\Iterator $iterator = null;

    public function __construct(
        private ZendArray $regular_list,
        private ?int $parent_node_id,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        if (!$this->started) {
            $this->started = true;
            $gen = $this->regular_list->getItemIteratorWithZendStringKeyIfAssoc($ctx->dereferencer);
            if ($gen instanceof \Iterator) {
                $this->iterator = $gen;
            } else {
                $this->iterator = (function () use ($gen): \Generator {
                    yield from $gen;
                })();
            }
            $this->iterator->rewind();
        }

        $iterator = $this->iterator;
        if ($iterator === null || !$iterator->valid()) {
            return;
        }

        try {
            /** @var int|Pointer $key */
            $key = $iterator->key();
            /** @var Zval $zval */
            $zval = $iterator->current();
            $iterator->next();

            $key_string = $key instanceof Pointer ? '' : (string)$key;

            if ($zval->isResource() && !is_null($zval->value->res)) {
                if ($iterator->valid()) {
                    $queue->push($this);
                }
                $queue->push(new EmitResourceJob(
                    $zval->value->res,
                    $this->parent_node_id,
                    $key_string,
                    EdgeStrength::Weak,
                ));
                return;
            }

            // Non-resource entry (shouldn't happen in regular_list, but be
            // defensive — skip and advance to the next bucket).
            if ($iterator->valid()) {
                $queue->push($this);
            }
        } catch (\Throwable) {
            if ($iterator->valid()) {
                $queue->push($this);
            }
        }
    }
}
