<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\PhpInternals;

use FFI;
use PHPUnit\Framework\TestCase;

/**
 * Class ZendTypeReaderTest
 * @package PhpProfiler\Lib\PhpInternals
 */
class ZendTypeReaderTest extends TestCase
{
    public function testReadAsZendString()
    {
        $reader = new ZendTypeReader(ZendTypeReader::V80);
        $string_size = $reader->sizeOf('zend_string');
        $data = FFI::new("char[{$string_size}]");
        $string = $reader->readAs('zend_string', $data);
        var_dump($string);
    }
}
