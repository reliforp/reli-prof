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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendOpArrayHeaderMemoryLocation;

class UserFunctionDefinitionContext extends FunctionDefinitionContext
{
    public function __construct(
        public ZendOpArrayHeaderMemoryLocation $memory_location,
    ) {
    }

    public function getFunctionName(): string
    {
        return $this->memory_location->function_name;
    }

    public function getOpArrayAddress(): int
    {
        return $this->memory_location->address;
    }

    public function isClosureOf(UserFunctionDefinitionContext $context): bool
    {
        return $context->isThisContext(
            $this->memory_location->file,
            $this->memory_location->line_start,
        );
    }

    public function isThisContext(
        string $file,
        int $line,
    ): bool {
        if ($this->memory_location->file !== $file) {
            return false;
        }
        if ($this->memory_location->line_start > $line) {
            return false;
        }
        if ($this->memory_location->line_end < $line) {
            return false;
        }
        return true;
    }

    public function getContexts(): iterable
    {
        return ['#is_internal' => false];
    }
}
