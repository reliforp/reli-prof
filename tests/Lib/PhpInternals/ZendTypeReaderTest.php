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

namespace Reli\Lib\PhpInternals;

use FFI;
use Reli\BaseTestCase;

class ZendTypeReaderTest extends BaseTestCase
{
    public function testReadAsZendString()
    {
        $reader = new ZendTypeReader(ZendTypeReader::V74);
        $string_size = $reader->sizeOf('zend_string');
        $data = FFI::new("char[{$string_size}]");
        FFI::memset($data, 0, $string_size);
        /** @var CastedCData<FFI\PhpInternals\zend_string> $string */
        $string = $reader->readAs('zend_string', $data);
        $this->assertSame(0, $string->casted->gc->refcount);
        $this->assertSame(0, $string->casted->gc->u->type_info);
        $this->assertSame(0, $string->casted->h);
        $this->assertSame(0, $string->casted->len);
        $this->assertSame(chr(0), $string->casted->val[0]);
    }
}
