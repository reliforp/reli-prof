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

use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\Process\Pointer\Dereferencer;

final class ZendStringMemoryLocation extends RefcountedMemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        int $refcount,
        int $type_info,
        public string $value,
    ) {
        parent::__construct(
            $address,
            $size,
            $refcount,
            $type_info,
        );
    }

    public static function fromZendString(
        ZendString $zend_string,
        Dereferencer $dereferencer,
    ): self {
        $raw_string = $zend_string->toString($dereferencer);
        return new self(
            $zend_string->getPointer()->address,
            $zend_string->getSize(),
            $zend_string->gc->refcount,
            $zend_string->gc->type_info,
            $raw_string,
        );
    }
}
