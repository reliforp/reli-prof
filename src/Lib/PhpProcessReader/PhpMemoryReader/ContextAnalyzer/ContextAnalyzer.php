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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer;

use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use WeakMap;

final class ContextAnalyzer
{
    private int $node_id = 0;

    public function analyze(ReferenceContext $reference_context, WeakMap $memo = null): array
    {
        if ($memo === null) {
            $memo = new WeakMap();
        }

        $result = [];
        foreach ($reference_context->getLinks() as $link_name => $linked_context) {
            if (isset($memo[$linked_context])) {
                $result[$link_name] = [
                    '#reference_node_id' => $memo[$linked_context],
                ];
                continue;
            }
            $memo[$linked_context] = $this->node_id;
            $node = [
                '#node_id' => $this->node_id,
                '#type' => $linked_context->getName(),
            ];
            if ($linked_context->getLocations() !== []) {
                $node['#locations'] = $linked_context->getLocations();
            }
            $contexts = $linked_context->getContexts();
            if (!is_array($contexts)) {
                $contexts = iterator_to_array($contexts);
            }
            if ($contexts !== []) {
                $node += $contexts;
            }
            $this->node_id++;
            $result[$link_name] = $node + $this->analyze($linked_context, $memo);
        }
        return $result;
    }
}
