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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayTableOverheadMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\DefinedFunctionsContext;

/**
 * Emit the function_table root branch.
 * Functions are leaf-like (no deep recursion through zval), so we collect inline.
 */
final class EmitFunctionTableJob implements CollectorJob
{
    public function __construct(
        private ZendArray $array,
        private ?int $parent_node_id = null,
        private string $link_name = 'function_table',
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $array_header_location = ZendArrayMemoryLocation::fromZendArray($this->array);
        $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($this->array);
        $array_table_overhead_location = ZendArrayTableOverheadMemoryLocation::fromZendArrayAndUsedLocation(
            $this->array,
            $array_table_location,
        );

        $ctx->memory_locations->add($array_header_location);
        $ctx->memory_locations->add($array_table_location);
        $ctx->memory_locations->add($array_table_overhead_location);

        $defined_functions_context = new DefinedFunctionsContext(
            $array_header_location,
            $array_table_location,
        );

        /** @var list<FunctionCollectionResult> */
        $deferred_all = [];
        foreach ($this->array->getItemIterator($ctx->dereferencer) as $function_name => $zval) {
            assert(is_string($function_name));
            assert(!is_null($zval->value->func));
            // PHP lower-cases function names in the global function_table
            // for case-insensitive dispatch. Resolve the canonical name
            // from the function struct so user code declared as
            // `myCamelCase()` doesn't appear as `mycamelcase` in paths.
            $canonical_name = CollectorHelpers::resolveCanonicalFunctionName(
                $zval->value->func,
                $function_name,
                $ctx,
            );
            $result = CollectorHelpers::collectZendFunctionPointer($zval->value->func, $ctx);
            if (is_int($result)) {
                $defined_functions_context->add($canonical_name, $result);
            } else {
                $defined_functions_context->add($canonical_name, $result->context);
                if ($result->deferred_arrays !== []) {
                    $deferred_all[] = $result;
                }
            }
        }

        $ctx->emitNode($defined_functions_context, $this->parent_node_id, $this->link_name);
        $fallback_pid = $defined_functions_context->getMemoNodeId();

        foreach ($deferred_all as $r) {
            foreach ($r->deferred_arrays as [$arr_pointer, $arr_link, $parent_ctx]) {
                $raw_pid = $parent_ctx->getMemoNodeId();
                $pid = $raw_pid !== null
                    ? ($raw_pid < 0 ? -$raw_pid - 1 : $raw_pid)
                    : ($fallback_pid !== null && $fallback_pid >= 0 ? $fallback_pid : null);
                /** @psalm-suppress ArgumentTypeCoercion */
                $queue->push(new EmitArrayJob($arr_pointer, $pid, $arr_link));
            }
        }
    }
}
