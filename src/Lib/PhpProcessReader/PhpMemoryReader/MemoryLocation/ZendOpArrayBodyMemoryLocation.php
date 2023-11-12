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

class ZendOpArrayBodyMemoryLocation extends MemoryLocation
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
        assert($zend_function->op_array->opcodes !== null);
        if (self::hasTrailingLiterals($zend_function, $zend_type_reader)) {
            $literals_size = $zend_function->op_array->last_literal * $zend_type_reader->sizeOf('zval');
            return new self(
                $zend_function->op_array->opcodes->address,
                $zend_type_reader->sizeOf('zend_op') * $zend_function->op_array->last + $literals_size,
                $function_name
            );
        }
        return new self(
            $zend_function->op_array->opcodes->address,
            $zend_type_reader->sizeOf('zend_op') * $zend_function->op_array->last,
            $function_name
        );
    }

    private static function hasTrailingLiterals(ZendFunction $zend_function, ZendTypeReader $zend_type_reader): bool
    {
        assert($zend_function->op_array->opcodes !== null);
        if (is_null($zend_function->op_array->literals)) {
            return false;
        }
        $literals_address = $zend_function->op_array->literals->address;
        $op_array_opcodes_address = $zend_function->op_array->opcodes->address;
        $opcodes_size = $zend_type_reader->sizeOf('zend_op') * $zend_function->op_array->last;
        $aligned_opcodes_end = self::getAlignedAddress(
            $op_array_opcodes_address + $opcodes_size,
            16,
        );

        return $aligned_opcodes_end === $literals_address;
    }

    private static function getAlignedAddress(int $address, int $align): int
    {
        if ($address % $align === 0) {
            return $address;
        }
        return $address + ($align - ($address % $align));
    }
}
