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

        static $skip_count = 0;
        static $exc_count = 0;
        static $emit_count = 0;
        // Find the next valid bucket
        while ($iterator->valid()) {
            try {
                /** @var int $key */
                $key = $iterator->key();
                /** @var Pointer<ZendObject> $bucket */
                $bucket = $iterator->current();
                $iterator->next();
            } catch (\Throwable $e) {
                $exc_count++;
                if ($exc_count <= 5) {
                    \fwrite(\STDERR, "[reli-debug] iter key/current threw: " . get_class($e) . ": " . $e->getMessage() . "\n");
                }
                continue;
            }

            if ($key === 0) {
                $skip_count++;
                continue;
            }
            if ($bucket->address & 1) {
                $skip_count++;
                continue;
            }
            if ($bucket->address === 0) {
                $skip_count++;
                continue;
            }
            if ($key >= $this->top) {
                \fwrite(\STDERR, sprintf("[reli-debug] iter DONE: emit=%d skip=%d exc=%d top=%d key=%d\n", $emit_count, $skip_count, $exc_count, $this->top, $key));
                return; // Done
            }

            // Re-push self for next bucket (processed after this bucket's subtree)
            if ($iterator->valid()) {
                $queue->push($this);
            }

            $emit_count++;
            if ($emit_count <= 3 || $emit_count % 100000 === 0) {
                \fwrite(\STDERR, sprintf("[reli-debug] emit #%d key=%d addr=0x%x\n", $emit_count, $key, $bucket->address));
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
        \fwrite(\STDERR, sprintf("[reli-debug] iter EXHAUSTED: emit=%d skip=%d exc=%d top=%d\n", $emit_count, $skip_count, $exc_count, $this->top));
    }
}
