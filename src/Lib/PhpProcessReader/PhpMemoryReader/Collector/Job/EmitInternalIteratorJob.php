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
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Walks a `__iterator_wrapper` instance's `data` zval at the
 * `zend_object_iterator` layout offset, exposing the wrapped iterable
 * (typically a Generator from `foreach (gen() as ...)`).
 *
 * The wrapper has zero PHP-declared properties — the wrapped iterable
 * lives only in the C-level `zend_object_iterator.data` slot — so a
 * generic object walker would never see it.
 */
final class EmitInternalIteratorJob implements CollectorJob
{
    public function __construct(
        private ZendObject $object,
        private ?int $object_node_id,
        CollectorContext $ctx,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        // zend_object_iterator layout:
        //   offset 0..55  std (zend_object base, 56B)
        //   offset 56..71 data (zval, 16B)  <-- wrapped iterable
        //   offset 72..79 funcs (function table ptr, ignored)
        //   offset 80..87 index (long, ignored)
        [$data_offset, $data_size] = $ctx->zend_type_reader->getOffsetAndSizeOfMember(
            'zend_object_iterator',
            'data',
        );

        $object_address = $this->object->getPointer()->address;
        $data_pointer = new Pointer(
            Zval::class,
            $object_address + $data_offset,
            $data_size,
        );

        try {
            $data_zval = $ctx->dereferencer->deref($data_pointer);
        } catch (\Throwable) {
            return;
        }

        $queue->push(new ResolveZvalJob(
            $data_zval,
            $this->object_node_id,
            'data',
        ));
    }
}
