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

namespace PhpProfiler\Lib\File;

use PHPUnit\Framework\TestCase;

class NativeFileReaderTest extends TestCase
{
    public function testReadAll()
    {
        $reader = new NativeFileReader();
        $this->assertSame(
            file_get_contents(__FILE__),
            $reader->readAll(__FILE__)
        );
    }
}
