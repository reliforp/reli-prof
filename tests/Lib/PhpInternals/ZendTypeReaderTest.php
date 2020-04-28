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
        FFI::memset($data, 0, $string_size);
        /** @var FFI\PhpInternals\zend_string $string */
        $string = $reader->readAs('zend_string', $data);
        $this->assertSame(0, $string->gc->refcount);
        $this->assertSame(0, $string->gc->u->type_info);
        $this->assertSame(0, $string->h);
        $this->assertSame(0, $string->len);
        $this->assertSame(chr(0), $string->val[0]);
    }
}
