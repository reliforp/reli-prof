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

use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\Process\MemoryLocation;

final class ZendArrayTableMemoryLocation extends MemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        public bool $is_packed,
    ) {
        parent::__construct($address, $size);
    }

    public static function fromZendArray(ZendArray $zend_array): self
    {
        return new self(
            $zend_array->getRealTableAddress(),
            $zend_array->getUsedTableSize(),
            $zend_array->isPacked(),
        );
    }
}
