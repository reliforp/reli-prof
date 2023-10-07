<?php

namespace Reli\Lib\PhpInternals\Types\Zend;

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

class ZendObject implements Dereferencable
{
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

    private ZvalArray $properties_table;

    /** @param CastedCData<zend_object> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata
    ) {
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

    /** @return iterable<Zval> */
    public function getPropertiesIterator(
        Dereferencer $dereferencer,
        ZendTypeReader $type_reader,
        Pointer $object_pointer,
    ): iterable {
        $class_entry = $dereferencer->deref($this->ce);
        [$table_offset,] = $type_reader->getOffsetAndSizeOfMember(
            ZendObject::getCTypeName(),
            'properties_table',
        );
        $property_count = $class_entry->properties_info->count();
        if ($property_count === 0) {
            return;
        }
        if (!isset($this->properties)) {
            $table_size = $property_count * $type_reader->sizeOf(Zval::getCTypeName());
            $properties_table_pointer = new Pointer(
                ZvalArray::class,
                $object_pointer->address + $table_offset,
                $table_size,
            );
            $this->properties_table = $dereferencer->deref($properties_table_pointer);
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
            yield $name => $this->properties_table[$real_offset / 16];
        }
    }

    public function isEnum(Dereferencer $dereferencer): bool
    {
        $ce = $dereferencer->deref($this->ce);
        return $ce->isEnum();
    }
    public static function getCTypeName(): string
    {
        return 'zend_object';
    }

    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        return new static($casted_cdata);
    }
}