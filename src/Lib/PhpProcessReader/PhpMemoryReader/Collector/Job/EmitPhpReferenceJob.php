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

use Reli\Lib\PhpInternals\Types\Zend\ZendReference;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendReferenceMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Emit a PHP reference (&$var) and push a job for the referenced value.
 * Replaces collectPhpReferencePointer.
 */
final class EmitPhpReferenceJob implements CollectorJob
{
    /** @param Pointer<ZendReference> $pointer */
    public function __construct(
        private Pointer $pointer,
        private ?int $parent_node_id,
        private string $link_name,
        private EdgeStrength $edge_strength = EdgeStrength::Strong,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $address = $this->pointer->address;

        // Dedup
        if ($ctx->memory_locations->has($address)) {
            $existing_node_id = $ctx->address_map[$address] ?? null;
            if ($existing_node_id !== null) {
                if ($this->parent_node_id !== null) {
                    $ctx->sink->emitReference(
                        $existing_node_id,
                        $this->parent_node_id,
                        $this->link_name,
                        $this->edge_strength,
                    );
                }
                return;
            }
            $cached = $ctx->context_pools->php_reference_context_pool->getContextByAddress($address);
            if ($cached !== null) {
                $node_id = $ctx->emitNode($cached, $this->parent_node_id, $this->link_name, $this->edge_strength);
                if ($node_id >= 0) {
                    $ctx->address_map[$address] = $node_id;
                }
                return;
            }
        }

        $php_reference = $ctx->dereferencer->deref($this->pointer);
        $memory_location = ZendReferenceMemoryLocation::fromZendReference($php_reference);
        $ctx->memory_locations->add($memory_location);
        $php_reference_context = $ctx->context_pools
            ->php_reference_context_pool
            ->getContextForLocation($memory_location);

        // Emit the reference node first, get its node_id
        $node_id = $ctx->emitNode(
            $php_reference_context,
            $this->parent_node_id,
            $this->link_name,
            $this->edge_strength,
        );
        if ($node_id >= 0) {
            $ctx->address_map[$address] = $node_id;
        }

        // Push job for the referenced value
        $queue->push(new ResolveZvalJob(
            $php_reference->val,
            $node_id >= 0 ? $node_id : null,
            'referenced',
        ));
    }
}
