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

namespace PhpProfiler\Lib\Dwarf\Expression\Opcodes\LiteralEncodings;

use PhpProfiler\Lib\Dwarf\Expression\ExpressionContext;
use PhpProfiler\Lib\Dwarf\Expression\Opcodes\Opcode;
use PhpProfiler\Lib\Dwarf\Expression\Stack;

final class AddrConstX implements Opcode
{
    public function __construct(
        private ExpressionContext $expression_context,
    ) {
    }

    public function execute(Stack $stack, ...$operands): int
    {
        $unit_die = $this->expression_context->getCompilationUnit()->unit_die;
        $address_table = $this->expression_context->getExecutableFile()->debug_addr->address_table;
        $address_base = $unit_die->getAddressBase();
        $address = $operands[0] * $address_table->address_table_header->address_size + $address_base;
        $index = $address / $address_table->address_table_header->address_size;

        $stack->push($address_table->addresses[$index]);
        return 1;
    }
}