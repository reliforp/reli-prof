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

use PHPUnit\Framework\TestCase;

class ExpressionTest extends TestCase
{
    /** @dataProvider dataProvider */
    public function testExecute($expected, $operations): void
    {
        $expression = new Expression(
            new ExpressionContext(),
            new Stack([]),
            ...$operations
        );
        $this->assertSame(
            $expected,
            $expression->execute()
        );
    }

    public function dataProvider(): array
    {
        return [
            'simple literal 0' => [
                0,
                [
                    new Operation(Operation::DW_OP_lit0),
                ]
            ],
            'simple literal 31' => [
                31,
                [
                    new Operation(Operation::DW_OP_lit31),
                ]
            ],
            'simple addr' => [
                0x1234,
                [
                    new Operation(Operation::DW_OP_addr, 0x1234),
                ]
            ],
        ];
    }
}
