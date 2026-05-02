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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\RegularListContext;

/**
 * Emit the EG(regular_list) root branch and push a bucket iterator.
 *
 * Mirrors EmitObjectsStoreJob in spirit: regular_list is the global registry
 * of active resources, but the references it holds should not pin a resource
 * as "live" on its own — otherwise every registered resource looks reachable.
 * All edges out of this root are emitted with EdgeStrength::Weak so that
 * resources reachable only via regular_list show up as leak candidates,
 * the same way unrooted objects do under objects_store.
 *
 * Each entry's zval is IS_RESOURCE; we dispatch directly to EmitResourceJob
 * (which carries the existing PHP_STREAM heuristic) rather than going
 * through ResolveZvalJob, because we want to override the edge strength
 * and avoid surprises if a non-resource ever sneaks into regular_list.
 */
final class EmitRegularListJob implements CollectorJob
{
    public function __construct(
        private ZendArray $regular_list,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        if ($this->regular_list->isUninitialized() || is_null($this->regular_list->arData)) {
            return;
        }

        $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($this->regular_list);
        $ctx->memory_locations->add($array_table_location);

        $regular_list_context = new RegularListContext($array_table_location);
        $root_node_id = $ctx->emitNode(
            $regular_list_context,
            null,
            'regular_list',
            EdgeStrength::Weak,
        );
        $parent = $root_node_id >= 0 ? $root_node_id : null;

        $queue->push(new RegularListBucketIteratorJob(
            $this->regular_list,
            $parent,
        ));
    }
}
