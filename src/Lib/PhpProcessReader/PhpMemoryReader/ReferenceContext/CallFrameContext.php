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

class CallFrameContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public string $function_name,
        public int $lineno,
    ) {
    }

    public function getLocalVariables(): ?CallFrameVariableTableContext
    {
        /** @var CallFrameVariableTableContext|null */
        return $this->referencing_contexts['local_variables'] ?? null;
    }

    public function getLocalVariable(string $variable_name): ?ReferenceContext
    {
        return $this->getLocalVariables()?->getVariable($variable_name) ?? null;
    }

    public function getContexts(): iterable
    {
        return [
            'function_name' => $this->function_name,
            'lineno' => $this->lineno,
        ];
    }
}
