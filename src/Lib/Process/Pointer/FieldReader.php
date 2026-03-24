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

namespace Reli\Lib\Process\Pointer;

use FFI;
use FFI\CData;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\MemoryReader\MemoryReaderInterface;
use Reli\Lib\Process\ProcessSpecifier;

final class FieldReader
{
    public function __construct(
        private MemoryReaderInterface $memory_reader,
        private ProcessSpecifier $process_specifier,
        private ZendTypeReader $type_reader,
        private PointedTypeResolver $pointed_type_resolver,
    ) {
    }

    /**
     * @template T of Dereferencable
     * @param Pointer<Dereferencable> $struct_pointer
     * @param class-string<T> $pointed_type
     * @return Pointer<T>|null
     */
    public function readPointerField(
        Pointer $struct_pointer,
        string $field_name,
        string $pointed_type,
        ?string $struct_type_override = null,
    ): ?Pointer {
        $struct_type = $struct_type_override ?? $struct_pointer->getCTypeNameOfType();
        [$offset, $size] = $this->type_reader->getOffsetAndSizeOfMember(
            $struct_type,
            $field_name,
        );
        $buffer = $this->memory_reader->read(
            $this->process_specifier->pid,
            $struct_pointer->address + $offset,
            $size,
        );
        /** @var \FFI\CInteger $addr_cdata */
        $addr_cdata = FFI::cast('long', $buffer);
        $addr = $addr_cdata->cdata;
        if ($addr === 0) {
            return null;
        }
        return new Pointer(
            $pointed_type,
            $addr,
            $this->type_reader->sizeOf($pointed_type::getCTypeName()),
        );
    }

    /**
     * @param Pointer<Dereferencable> $struct_pointer
     */
    public function readIntField(
        Pointer $struct_pointer,
        string $field_name,
        ?string $struct_type_override = null,
    ): int {
        $struct_type = $struct_type_override ?? $struct_pointer->getCTypeNameOfType();
        [$offset, $size] = $this->type_reader->getOffsetAndSizeOfMember(
            $struct_type,
            $field_name,
        );
        $buffer = $this->memory_reader->read(
            $this->process_specifier->pid,
            $struct_pointer->address + $offset,
            $size,
        );
        /** @var \FFI\CInteger $casted */
        $casted = match ($size) {
            1 => FFI::cast('uint8_t', $buffer),
            4 => FFI::cast('uint32_t', $buffer),
            8 => FFI::cast('long', $buffer),
            default => FFI::cast('long', $buffer),
        };
        return $casted->cdata;
    }

    /**
     * Read an embedded struct (or union member) from the remote process
     * and return it as cast CData suitable for constructing wrapper objects.
     *
     * @param Pointer<Dereferencable> $struct_pointer
     * @return CastedCData<CData>
     */
    public function readEmbeddedStructCData(
        Pointer $struct_pointer,
        string $field_name,
        string $embedded_type,
    ): CastedCData {
        [$offset,] = $this->type_reader->getOffsetAndSizeOfMember(
            $struct_pointer->getCTypeNameOfType(),
            $field_name,
        );
        $size = $this->type_reader->sizeOf($embedded_type);
        $buffer = $this->memory_reader->read(
            $this->process_specifier->pid,
            $struct_pointer->address + $offset,
            $size,
        );
        return $this->type_reader->readAs($embedded_type, $buffer);
    }

    /**
     * Read an embedded struct field and return a fully constructed Dereferencable.
     *
     * @template T of Dereferencable
     * @param Pointer<Dereferencable> $struct_pointer
     * @param class-string<T> $php_type
     * @return T
     * @psalm-suppress InvalidReturnType psalm cannot narrow T through resolve()
     */
    public function readEmbeddedDereferencable(
        Pointer $struct_pointer,
        string $field_name,
        string $c_type,
        string $php_type,
    ): mixed {
        [$offset,] = $this->type_reader->getOffsetAndSizeOfMember(
            $struct_pointer->getCTypeNameOfType(),
            $field_name,
        );
        $size = $this->type_reader->sizeOf($c_type);
        $buffer = $this->memory_reader->read(
            $this->process_specifier->pid,
            $struct_pointer->address + $offset,
            $size,
        );
        $casted_cdata = $this->type_reader->readAs($c_type, $buffer);
        $pointer = new Pointer($php_type, $struct_pointer->address + $offset, $size);
        $resolved_type = $this->pointed_type_resolver->resolve($php_type);
        if (is_a($resolved_type, PointedTypeResolverAware::class, true)) {
            /**
             * @psalm-suppress TooManyArguments
             * @psalm-suppress InvalidReturnStatement
             */
            return $resolved_type::fromCastedCData($casted_cdata, $pointer, $this->pointed_type_resolver);
        }
        /** @psalm-suppress InvalidReturnStatement */
        return $resolved_type::fromCastedCData($casted_cdata, $pointer);
    }
}
