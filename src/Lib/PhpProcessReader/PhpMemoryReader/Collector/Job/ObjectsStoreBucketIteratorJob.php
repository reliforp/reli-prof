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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Iterator job that processes one objects_store bucket per tick.
 */
final class ObjectsStoreBucketIteratorJob implements CollectorJob
{
    private bool $started = false;

    /**
     * @param iterable<int, Pointer<ZendObject>> $bucket_iterator
     */
    public function __construct(
        private iterable $bucket_iterator,
        private int $top,
        private ?int $parent_node_id,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        if (!$this->started) {
            $this->started = true;
            if ($this->bucket_iterator instanceof \Iterator) {
                $this->bucket_iterator->rewind();
            } else {
                // Wrap in a generator
                $original = $this->bucket_iterator;
                $this->bucket_iterator = (function () use ($original): \Generator {
                    yield from $original;
                })();
                /** @var \Iterator $this->bucket_iterator */
                $this->bucket_iterator->rewind();
            }
        }

        /** @var \Iterator<int, Pointer<ZendObject>> $iterator */
        $iterator = $this->bucket_iterator;

        // Find the next valid bucket
        while ($iterator->valid()) {
            /** @var int $key */
            $key = $iterator->key();
            /** @var Pointer<ZendObject> $bucket */
            $bucket = $iterator->current();

            $iterator->next();

            if ($key === 0) {
                continue;
            }
            if ($bucket->address & 1) {
                continue;
            }
            if ($bucket->address === 0) {
                continue;
            }
            if ($key >= $this->top) {
                return; // Done
            }

            // Re-push self for next bucket (processed after this bucket's subtree)
            if ($iterator->valid()) {
                $queue->push($this);
            }

            // Push the object emit job (processed next = DFS)
            $queue->push(new EmitObjectJob(
                $bucket,
                $this->parent_node_id,
                (string)$key,
                EdgeStrength::Weak,
            ));

            return;
        }
    }
}
