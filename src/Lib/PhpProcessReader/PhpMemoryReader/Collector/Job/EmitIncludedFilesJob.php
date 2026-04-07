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
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\IncludedFilesContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Emit the included_files root branch. Leaf-like (only string children).
 */
final class EmitIncludedFilesJob implements CollectorJob
{
    public function __construct(
        private ZendArray $included_files,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        $array_table_location = ZendArrayTableMemoryLocation::fromZendArray($this->included_files);
        $included_files_context = new IncludedFilesContext($array_table_location);
        $ctx->memory_locations->add($array_table_location);

        $iterator = $this->included_files->getItemIteratorWithZendStringKeyIfAssoc($ctx->dereferencer);
        foreach ($iterator as $filename => $_) {
            assert($filename instanceof Pointer);
            $raw_string = $ctx->dereferencer->deref($filename)->toString($ctx->dereferencer);
            $included_file_context = CollectorHelpers::collectZendStringPointer($filename, $ctx);
            $included_files_context->add($raw_string, $included_file_context);
        }

        $ctx->emitNode($included_files_context, null, 'included_files');
    }
}
