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

use Reli\Lib\PhpInternals\Types\Zend\ZendExecuteData;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;

/**
 * Iterator job for call frame local variables.
 * Processes one variable per tick.
 */
final class CallFrameVariableIteratorJob implements CollectorJob
{
    /** @var \Iterator|null */
    private ?\Iterator $iterator = null;
    private bool $started = false;

    public function __construct(
        private ZendExecuteData $execute_data,
        private ?int $variable_table_node_id,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        if (!$this->started) {
            $this->started = true;
            $gen = $this->execute_data->getVariables(
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

        /** @var string $name */
        $name = $this->iterator->key();
        /** @var Zval $value */
        $value = $this->iterator->current();

        // Advance iterator
        $this->iterator->next();

        // For DFS: push continuation first, then child
        if ($this->iterator->valid()) {
            $queue->push($this);
        }

        // Push zval resolution job
        $queue->push(new ResolveZvalJob(
            $value,
            $this->variable_table_node_id,
            $name,
        ));
    }
}
