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

namespace PhpProfiler\Lib\Dwarf\Expression\Opcodes\ArithmeticAndLogical;

use PhpProfiler\Lib\Dwarf\Expression\Opcodes\Opcode;
use PhpProfiler\Lib\Dwarf\Expression\Stack;

final class Div implements Opcode
{
    public function execute(Stack $stack, ...$operands): int
    {
        $top = $stack->pop();
        $stack->push(intdiv($stack->pop(), $top));
        return 1;
    }
}
