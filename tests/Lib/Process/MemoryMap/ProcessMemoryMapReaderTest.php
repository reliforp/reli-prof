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

namespace Reli\Lib\Process\MemoryMap;

use Reli\BaseTestCase;

class ProcessMemoryMapReaderTest extends BaseTestCase
{
    public function testRead()
    {
        $result = (new ProcessMemoryMapReader())->read(getmypid());
        $first_line = strtok($result, "\n");
        $this->assertMatchesRegularExpression(
            // phpcs:ignore Generic.Files.LineLength.TooLong
            '/[0-9a-f]+-[0-9a-f]+ [r\-][w\-][x\-][sp\-] [0-9a-f]+ [0-9a-z][0-9a-z][0-9a-z]?:[0-9a-z][0-9a-z][0-9a-z]? [0-9]+ +[^ ].*/',
            $first_line
        );
    }
}
