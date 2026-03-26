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

namespace Reli\Lib\Dwarf;

use Reli\BaseTestCase;

class RegisterRuleTest extends BaseTestCase
{
    public function testUndefined(): void
    {
        $rule = RegisterRule::undefined();
        $this->assertSame(RegisterRuleType::Undefined, $rule->type);
    }

    public function testSameValue(): void
    {
        $rule = RegisterRule::sameValue();
        $this->assertSame(RegisterRuleType::SameValue, $rule->type);
    }

    public function testOffset(): void
    {
        $rule = RegisterRule::offset(-16);
        $this->assertSame(RegisterRuleType::Offset, $rule->type);
        $this->assertSame(-16, $rule->value);
    }

    public function testValOffset(): void
    {
        $rule = RegisterRule::valOffset(8);
        $this->assertSame(RegisterRuleType::ValOffset, $rule->type);
        $this->assertSame(8, $rule->value);
    }

    public function testRegister(): void
    {
        $rule = RegisterRule::register(6);
        $this->assertSame(RegisterRuleType::Register, $rule->type);
        $this->assertSame(6, $rule->value);
    }

    public function testExpression(): void
    {
        $rule = RegisterRule::expression("\x70\x00");
        $this->assertSame(RegisterRuleType::Expression, $rule->type);
        $this->assertSame("\x70\x00", $rule->expressionBytes);
    }

    public function testValExpression(): void
    {
        $rule = RegisterRule::valExpression("\x71\x08");
        $this->assertSame(RegisterRuleType::ValExpression, $rule->type);
        $this->assertSame("\x71\x08", $rule->expressionBytes);
    }
}
