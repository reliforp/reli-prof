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

namespace PhpProfiler\Lib\Process\MemoryMap;

use PHPUnit\Framework\TestCase;

/**
 * Class ProcessMemoryMapReaderTest
 * @package PhpProfiler\ProcessReader
 */
class ProcessMemoryMapReaderTest extends TestCase
{
    public function testRead()
    {
        $result = (new ProcessMemoryMapReader())->read(getmypid());
        $first_line = strtok($result, "\n");
        $this->assertMatchesRegularExpression(
            // phpcs:ignore Generic.Files.LineLength.TooLong
            '/[0-9a-f]{12,16}-[0-9a-f]{12,16} [r\-][w\-][x\-][p\-] [0-9a-f]{8} [0-9][0-9][0-9]?:[0-9][0-9] [0-9]+ +[^ ]+/',
            $first_line
        );
    }
}
