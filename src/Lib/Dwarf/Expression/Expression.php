<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Dwarf\Expression;

final class Expression
{
    private array $operations;
    private Stack $stack;

    public function __construct(
        private ExpressionContext $expression_context,
        Stack $stack,
        Operation ...$operations
    ) {
        $this->stack = clone $stack;
        $this->operations = $operations;
    }

    public function execute(): int
    {
        $length = count($this->operations);
        for ($pos = 0; $pos < $length; $pos += $step) {
            $operation = $this->operations[$pos];
            $step = $operation->getOpcode()->execute(
                $this->stack,
                ...$operation->getOperands(),
            );
        }
        return $this->stack->peakTop();
    }
}
