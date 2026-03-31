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

namespace Reli\Converter;

use Reli\BaseTestCase;

class ParsedCallFrameTest extends BaseTestCase
{
    public function testConstructWithAllProperties(): void
    {
        $context = new PhpSpyCompatibleDataContext(5);
        $frame = new ParsedCallFrame('my_func', '/path/to/file.php', 42, $context);

        $this->assertSame('my_func', $frame->function_name);
        $this->assertSame('/path/to/file.php', $frame->file_name);
        $this->assertSame(42, $frame->lineno);
        $this->assertSame($context, $frame->original_context);
    }

    public function testConstructWithoutContext(): void
    {
        $frame = new ParsedCallFrame('my_func', '/path/to/file.php', 42);

        $this->assertNull($frame->original_context);
    }
}
