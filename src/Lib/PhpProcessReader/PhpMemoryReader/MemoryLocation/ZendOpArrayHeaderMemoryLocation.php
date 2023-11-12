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

use Reli\Lib\PhpInternals\Types\Zend\ZendFunction;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\MemoryLocation;

class ZendOpArrayHeaderMemoryLocation extends MemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        public string $function_name
    ) {
        parent::__construct($address, $size);
    }

    public static function fromZendFunction(
        ZendFunction $zend_function,
        ZendTypeReader $zend_type_reader,
        string $function_name
    ): self {
        return new self(
            $zend_function->getPointer()->address,
            $zend_type_reader->sizeOf('zend_op_array'),
            $function_name,
        );
    }
}
