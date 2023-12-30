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

namespace Reli\Lib\PhpInternals\Types\Zend;

use FFI\CInteger;
use Reli\Lib\FFI\Cast;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * @psalm-consistent-constructor
 */
class ZendObject implements Dereferencable
{
    public ZendRefcountedH $zend_refcounted_h;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendClassEntry>|null
     */
    public ?Pointer $ce;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendArray>|null
     */
    public ?Pointer $properties;

    /** @psalm-suppress PropertyNotSetInConstructor */
    private ZvalArray $properties_table;
    private bool $properties_table_initialized = false;

    /**
     * @param CastedCData<\FFI\PhpInternals\zend_object> $casted_cdata
     * @param Pointer<ZendObject> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        $this->zend_refcounted_h = new ZendRefcountedH($casted_cdata->casted->gc);
        unset($this->properties);
        unset($this->ce);
        unset($this->properties_table);
    }
    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'ce' => $this->ce
                = $this->casted_cdata->casted->ce !== null
                ? Pointer::fromCData(
                    ZendClassEntry::class,
                    $this->casted_cdata->casted->ce,
                )
                : null
            ,
            'properties' => $this->properties
                = $this->casted_cdata->casted->properties !== null
                ? Pointer::fromCData(
                    ZendArray::class,
                    $this->casted_cdata->casted->properties,
                )
                : null
            ,
        };
    }

    public function getHandlersAddress(): int
    {
        assert($this->casted_cdata->casted->handlers !== null);
        return Cast::castPointerToInt(
            $this->casted_cdata->casted->handlers
        );
    }

    public function getMemorySize(
        Dereferencer $dereferencer,
    ): int {
        if ($this->ce === null) {
            return $this->pointer->size;
        }
        $class_entry = $dereferencer->deref($this->ce);
        $property_count = $class_entry->default_properties_count;
        return $this->pointer->size + ($property_count - 1) * 16;
    }

    /** @return iterable<Zval> */
    public function getPropertiesIterator(
        Dereferencer $dereferencer,
        ZendTypeReader $type_reader,
    ): iterable {
        if ($this->ce === null) {
            return;
        }
        $class_entry = $dereferencer->deref($this->ce);
        [$table_offset,] = $type_reader->getOffsetAndSizeOfMember(
            ZendObject::getCTypeName(),
            'properties_table',
        );
        $property_count = $class_entry->default_properties_count;
        if ($property_count === 0) {
            return;
        }
        if (!$this->properties_table_initialized) {
            $table_size = $property_count * $type_reader->sizeOf(Zval::getCTypeName());
            $properties_table_pointer = new Pointer(
                ZvalArray::class,
                $this->getPointer()->address + $table_offset,
                $table_size,
            );
            $this->properties_table = $dereferencer->deref($properties_table_pointer);
            $this->properties_table_initialized = true;
        }

        foreach ($class_entry->properties_info->getItemIterator($dereferencer) as $name => $item) {
            $property_info_pointer = $item->value->getAsPointer(
                ZendPropertyInfo::class,
                $type_reader->sizeOf(ZendPropertyInfo::getCTypeName()),
            );
            $property_info = $dereferencer->deref($property_info_pointer);
            if ($property_info->isStatic()) {
                continue;
            }
            $real_offset = $property_info->offset - $table_offset;
            yield $name => $this->properties_table[(int)($real_offset / 16)];
        }
    }

    public function isEnum(Dereferencer $dereferencer): bool
    {
        if ($this->ce === null) {
            return false;
        }
        $ce = $dereferencer->deref($this->ce);
        return $ce->isEnum();
    }
    public static function getCTypeName(): string
    {
        return 'zend_object';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /**
         * @var CastedCData<\FFI\PhpInternals\zend_object> $casted_cdata
         * @var Pointer<ZendObject> $pointer
         */
        return new static($casted_cdata, $pointer);
    }

    /** @return Pointer<ZendObject> */
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
