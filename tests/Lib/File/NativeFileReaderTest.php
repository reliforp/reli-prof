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

namespace Reli\Lib\File;

use Reli\BaseTestCase;

class NativeFileReaderTest extends BaseTestCase
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
