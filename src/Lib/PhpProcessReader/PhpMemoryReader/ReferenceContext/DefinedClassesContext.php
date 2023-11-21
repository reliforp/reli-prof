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

class DefinedClassesContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function getContexts(): iterable
    {
        return [
            '#count' => count($this->referencing_contexts),
        ];
    }
}
