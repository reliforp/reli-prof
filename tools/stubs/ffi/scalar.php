<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FFI;

class CInteger extends CData
{
    public int $cdata;
}

class CPointer extends CData
{
    public int $cdata;
}

/**
 * Class CArray
 *
 * @template-implements \ArrayAccess<int, int>
 */
class CArray extends CData implements \ArrayAccess, \Countable
{

    public function offsetExists($offset)
    {
        // TODO: Implement offsetExists() method.
    }

    public function offsetGet($offset)
    {
        // TODO: Implement offsetGet() method.
    }

    public function offsetSet($offset, $value)
    {
        // TODO: Implement offsetSet() method.
    }

    public function offsetUnset($offset)
    {
        // TODO: Implement offsetUnset() method.
    }
}