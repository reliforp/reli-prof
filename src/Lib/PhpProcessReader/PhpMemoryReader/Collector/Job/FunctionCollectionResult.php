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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\FunctionDefinitionContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Result of collectZendFunction containing the context and any
 * deferred array pointers that need EmitArrayJob processing.
 */
final class FunctionCollectionResult
{
    /**
     * @param list<array{Pointer<ZendArray>, string, ReferenceContext}> $deferred_arrays
     */
    public function __construct(
        public FunctionDefinitionContext $context,
        /** @var list<array{Pointer<ZendArray>, string, ReferenceContext}> */
        public array $deferred_arrays = [],
    ) {
    }
}
