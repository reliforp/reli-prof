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
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;

/**
 * Iterator job that processes one object property per tick.
 * Holds a properties iterator internally and re-pushes itself until exhausted.
 */
final class ObjectPropertiesIteratorJob implements CollectorJob
{
    /** @var \Iterator|null */
    private ?\Iterator $iterator = null;
    private bool $started = false;

    public function __construct(
        private ZendObject $object,
        private ?int $properties_node_id,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        if (!$this->started) {
            $this->started = true;
            $gen = $this->object->getPropertiesIterator(
                $ctx->dereferencer,
                $ctx->zend_type_reader,
            );
            if ($gen instanceof \Iterator) {
                $this->iterator = $gen;
            } else {
                $this->iterator = (function () use ($gen): \Generator {
                    yield from $gen;
                })();
            }
            $this->iterator->rewind();
        }

        if ($this->iterator === null || !$this->iterator->valid()) {
            return;
        }

        try {
            $name = (string)$this->iterator->key();
            /** @var Zval $property */
            $property = $this->iterator->current();

            $this->iterator->next();

            if ($this->iterator->valid()) {
                $queue->push($this);
            }

            $queue->push(new ResolveZvalJob(
                $property,
                $this->properties_node_id,
                $name,
            ));
        } catch (\Throwable) {
            $this->iterator->next();
            if ($this->iterator->valid()) {
                $queue->push($this);
            }
        }
    }
}
