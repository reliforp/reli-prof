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

class CfaRuleTest extends BaseTestCase
{
    public function testRegisterOffset(): void
    {
        $rule = CfaRule::registerOffset(7, 16);
        $this->assertSame(CfaRuleType::RegisterOffset, $rule->type);
        $this->assertSame(7, $rule->register);
        $this->assertSame(16, $rule->offset);
        $this->assertSame('', $rule->expressionBytes);
    }

    public function testExpression(): void
    {
        $rule = CfaRule::expression("\x70\x00");
        $this->assertSame(CfaRuleType::Expression, $rule->type);
        $this->assertSame("\x70\x00", $rule->expressionBytes);
    }
}
