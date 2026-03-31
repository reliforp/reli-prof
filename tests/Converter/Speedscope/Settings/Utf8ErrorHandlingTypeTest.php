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

namespace Reli\Converter\Speedscope\Settings;

use Reli\BaseTestCase;

class Utf8ErrorHandlingTypeTest extends BaseTestCase
{
    public function testIgnoreToFlag(): void
    {
        $this->assertSame(\JSON_INVALID_UTF8_IGNORE, Utf8ErrorHandlingType::Ignore->toFlag());
    }

    public function testSubstituteToFlag(): void
    {
        $this->assertSame(\JSON_INVALID_UTF8_SUBSTITUTE, Utf8ErrorHandlingType::Substitute->toFlag());
    }

    public function testFailToFlag(): void
    {
        $this->assertSame(0, Utf8ErrorHandlingType::Fail->toFlag());
    }
}
