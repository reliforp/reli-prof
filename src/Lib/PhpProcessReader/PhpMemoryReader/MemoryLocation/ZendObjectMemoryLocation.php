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

final class ZendObjectMemoryLocation extends RefcountedMemoryLocation
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
        $address = $zend_object->getPointer()->address;
        if ($class_name === \Fiber::class) {
            $size = $zend_type_reader->sizeOf('zend_fiber');
        } elseif (
            $class_name === \Closure::class
            and !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V71)
        ) {
            $size = $zend_type_reader->sizeOf('zend_closure');
        } elseif ($class_name === \Generator::class) {
            $size = $zend_type_reader->sizeOf('zend_generator');
        } elseif (
            $class_name === \WeakReference::class
            and !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V74)
        ) {
            [$std_offset] = $zend_type_reader->getOffsetAndSizeOfMember('zend_weakref', 'std');
            $address -= $std_offset;
            $size = $zend_object->getMemorySize($dereferencer) + $std_offset;
        } elseif (
            $class_name === \WeakMap::class
            and !$zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V80)
        ) {
            [$std_offset] = $zend_type_reader->getOffsetAndSizeOfMember('zend_weakmap', 'std');
            $address -= $std_offset;
            $size = $zend_object->getMemorySize($dereferencer) + $std_offset;
        } elseif ($class_name === \PDO::class) {
            [$std_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_dbh_object_t', 'std');
            $address -= $std_offset;
            $size = $zend_object->getMemorySize($dereferencer) + $std_offset;
        } elseif ($class_name === \PDOStatement::class) {
            [$std_offset] = $zend_type_reader->getOffsetAndSizeOfMember('pdo_stmt_t', 'std');
            $address -= $std_offset;
            $size = $zend_object->getMemorySize($dereferencer) + $std_offset;
        } else {
            $size = $zend_object->getMemorySize($dereferencer);
        }
        return new self(
            $address,
            $size,
            $zend_object->zend_refcounted_h->refcount,
            $zend_object->zend_refcounted_h->type_info,
            $class_name,
        );
    }
}
