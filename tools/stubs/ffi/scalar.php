<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FFI;

/** @template T */
class CData
{
    /** @var T */
    public $cdata;
}

/** @extends CData<int> */
class CInteger extends CData
{
    public int $cdata;
}

/** @extends CData<int> */
class CPointer extends CData
{
    public int $cdata;
}

/** @extends CData<int> */
class CChar extends CData
{
    public string $cdata;
}

/**
 * Class CArray
 *
 * @template T
 * @template-implements \ArrayAccess<int, T>
 * @extends CData<never>
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