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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;

/**
 * Emit the global_variables root branch.
 * Replaces collectGlobalVariables.
 */
final class EmitGlobalVariablesJob implements CollectorJob
{
    public function __construct(
        private ZendArray $symbol_table,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        // GlobalVariablesContext wraps an ArrayHeaderContext
        // We emit the array directly under the 'global_variables' link name
        EmitArrayJob::processZendArray(
            $this->symbol_table,
            null,
            'global_variables',
            EdgeStrength::Strong,
            $ctx,
            $queue,
        );
    }
}
