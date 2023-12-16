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
use Reli\Lib\Process\Pointer\Dereferencer;

class ZendOpArrayHeaderMemoryLocation extends MemoryLocation
{
    public function __construct(
        int $address,
        int $size,
        public string $function_name,
        public string $file,
        public int $line_start,
        public int $line_end,
    ) {
        parent::__construct($address, $size);
    }

    public static function fromZendFunction(
        ZendFunction $zend_function,
        ZendTypeReader $zend_type_reader,
        Dereferencer $dereferencer,
    ): self {
        return new self(
            $zend_function->getPointer()->address,
            $zend_type_reader->sizeOf('zend_op_array'),
            $zend_function->getFullyQualifiedFunctionName($dereferencer, $zend_type_reader),
            $zend_function->op_array->getFileName($dereferencer) ?? '',
            $zend_function->op_array->line_start,
            $zend_function->op_array->line_end,
        );
    }
}
