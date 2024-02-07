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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation;

use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencer;

class ZendObjectMemoryLocation extends RefcountedMemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        int $refcount,
        int $type_info,
        public string $class_name
    ) {
        parent::__construct($address, $size, $refcount, $type_info);
    }

    public static function fromZendObject(
        ZendObject $zend_object,
        Dereferencer $dereferencer,
        ZendTypeReader $zend_type_reader,
    ): self {
        assert($zend_object->ce !== null);
        $ce = $dereferencer->deref($zend_object->ce);
        $class_name = $ce->getClassName($dereferencer);
        if ($class_name === \Fiber::class) {
            $size = $zend_type_reader->sizeOf('zend_fiber');
        } elseif (
            $class_name === \Closure::class
            and !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V71)
        ) {
            $size = $zend_type_reader->sizeOf('zend_closure');
        } else {
            $size = $zend_object->getMemorySize($dereferencer);
        }
        return new self(
            $zend_object->getPointer()->address,
            $size,
            $zend_object->zend_refcounted_h->refcount,
            $zend_object->zend_refcounted_h->type_info,
            $class_name,
        );
    }
}
