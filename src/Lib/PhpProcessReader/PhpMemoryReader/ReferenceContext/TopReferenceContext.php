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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

final class TopReferenceContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public CallFramesContext $call_frames,
        public GlobalVariablesContext $global_variables,
        public DefinedFunctionsContext $function_table,
        public DefinedClassesContext $class_table,
        public GlobalConstantsContext $global_constants,
        public IncludedFilesContext $included_files,
        public ArrayHeaderContext $interned_strings,
        public ObjectsStoreContext $objects_store,
    ) {
    }

    public function getLinks(): iterable
    {
        return [
            'call_frames' => $this->call_frames,
            'global_variables' => $this->global_variables,
            'function_table' => $this->function_table,
            'class_table' => $this->class_table,
            'global_constants' => $this->global_constants,
            'included_files' => $this->included_files,
            'interned_strings' => $this->interned_strings,
            'objects_store' => $this->objects_store,
        ];
    }
}
