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

final class CallFrameVariableTableContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function getVariable(string $variable_name): ?ReferenceContext
    {
        return $this->referencing_contexts[$variable_name] ?? null;
    }

    public function getContexts(): iterable
    {
        return [
            '#count' => count($this->referencing_contexts),
        ];
    }
}
