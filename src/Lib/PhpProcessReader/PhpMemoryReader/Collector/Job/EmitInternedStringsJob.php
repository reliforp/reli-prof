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
 * Emit the interned_strings root branch.
 * Interned strings are stored in a ZendArray, so we use the array processing.
 */
final class EmitInternedStringsJob implements CollectorJob
{
    public function __construct(
        private ZendArray $interned_strings,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        EmitArrayJob::processZendArray(
            $this->interned_strings,
            null,
            'interned_strings',
            EdgeStrength::Strong,
            $ctx,
            $queue,
        );
    }
}
