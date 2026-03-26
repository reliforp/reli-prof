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

class UnwindTableRowTest extends BaseTestCase
{
    public function testGetRegisterRuleExisting(): void
    {
        $row = new UnwindTableRow(
            0x1000,
            CfaRule::registerOffset(7, 8),
            [16 => RegisterRule::offset(-8)],
        );
        $rule = $row->getRegisterRule(16);
        $this->assertSame(RegisterRuleType::Offset, $rule->type);
        $this->assertSame(-8, $rule->value);
    }

    public function testGetRegisterRuleMissingReturnsUndefined(): void
    {
        $row = new UnwindTableRow(0x1000, CfaRule::registerOffset(7, 8), []);
        $rule = $row->getRegisterRule(99);
        $this->assertSame(RegisterRuleType::Undefined, $rule->type);
    }

    public function testWithUpdated(): void
    {
        $row = new UnwindTableRow(
            0x1000,
            CfaRule::registerOffset(7, 8),
            [16 => RegisterRule::offset(-8)],
        );
        $new = $row->withUpdated(location: 0x2000);
        $this->assertSame(0x2000, $new->location);
        $this->assertSame(7, $new->cfaRule->register);
        $this->assertSame(-8, $new->getRegisterRule(16)->value);
    }
}
