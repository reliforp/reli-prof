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

use FFI\PhpInternals\zend_class_entry;
use Reli\Lib\FFI\Cast;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendClassEntry implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $type;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendString>
     */
    public Pointer $name;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $default_static_members_count;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $default_properties_count;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $num_interfaces;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $num_traits;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZvalArray>|null
     */
    public ?Pointer $default_properties_table;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZvalArray>|null
     */
    public ?Pointer $default_static_members_table;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<Zval>|null
     */
    public ?Pointer $static_members_table;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $function_table;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $constants_table;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendArray $properties_info;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $ce_flags;

    public ZendClassEntryInfo $info;

    private ?ZvalArray $static_properties_table_cache = null;

    /**
     * @param CastedCData<zend_class_entry> $casted_cdata
     * @param Pointer<ZendClassEntry> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->type);
        unset($this->name);
        unset($this->default_static_members_count);
        unset($this->static_members_table);
        unset($this->function_table);
        unset($this->constants_table);
        unset($this->ce_flags);
        unset($this->properties_info);
        unset($this->info);
        unset($this->default_properties_count);
        unset($this->default_properties_table);
        unset($this->default_static_members_table);
        unset($this->num_interfaces);
        unset($this->num_traits);
        $this->info = new ZendClassEntryInfo($this->casted_cdata->casted->info);
    }

    public function __get(string $field_name)
    {
        return match ($field_name) {
            'type' => $this->type = ord($this->casted_cdata->casted->type),
            'name' => $this->name = Pointer::fromCData(
                ZendString::class,
                $this->casted_cdata->casted->name,
            ),
            'default_static_members_count' => $this->default_static_members_count =
                $this->casted_cdata->casted->default_static_members_count
            ,
            'static_members_table' => $this->static_members_table =
                $this->casted_cdata->casted->static_members_table__ptr !== null
                ? Pointer::fromCData(
                    Zval::class,
                    $this->casted_cdata->casted->static_members_table__ptr,
                )
                : null
            ,
            'function_table' => $this->function_table = new ZendArray(
                new CastedCData(
                    $this->casted_cdata->casted->function_table,
                    $this->casted_cdata->casted->function_table,
                ),
                new Pointer(
                    ZendArray::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('function_table'),
                    \FFI::sizeof($this->casted_cdata->casted->function_table),
                ),
            ),
            'constants_table' => $this->constants_table = new ZendArray(
                new CastedCData(
                    $this->casted_cdata->casted->constants_table,
                    $this->casted_cdata->casted->constants_table,
                ),
                new Pointer(
                    ZendArray::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('constants_table'),
                    \FFI::sizeof($this->casted_cdata->casted->constants_table),
                ),
            ),
            'ce_flags' => $this->ce_flags = $this->casted_cdata->casted->ce_flags,
            'properties_info' => $this->properties_info = new ZendArray(
                new CastedCData(
                    $this->casted_cdata->casted->properties_info,
                    $this->casted_cdata->casted->properties_info,
                ),
                new Pointer(
                    ZendArray::class,
                    $this->pointer->address
                    +
                    \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('properties_info'),
                    \FFI::sizeof($this->casted_cdata->casted->properties_info),
                ),
            ),
            'info' => $this->info = new ZendClassEntryInfo($this->casted_cdata->casted->info),
            'default_properties_count' => $this->default_properties_count =
                $this->casted_cdata->casted->default_properties_count
            ,
            'default_properties_table' => $this->default_properties_table =
                $this->casted_cdata->casted->default_properties_table !== null
                ? new Pointer(
                    ZvalArray::class,
                    Cast::castPointerToInt(
                        $this->casted_cdata->casted->default_properties_table
                    ),
                    16 * $this->casted_cdata->casted->default_properties_count,
                )
                : null
            ,
            'default_static_members_table' => $this->default_static_members_table =
                $this->casted_cdata->casted->default_static_members_table !== null
                ? new Pointer(
                    ZvalArray::class,
                    Cast::castPointerToInt(
                        $this->casted_cdata->casted->default_static_members_table
                    ),
                    16 * $this->casted_cdata->casted->default_static_members_count,
                )
                : null
            ,
            'num_interfaces' => $this->num_interfaces = $this->casted_cdata->casted->num_interfaces,
            'num_traits' => $this->num_traits = $this->casted_cdata->casted->num_traits,
        };
    }

    /** @return iterable<string, ZendPropertyInfo> */
    public function iteratePropertyInfo(
        Dereferencer $dereferencer,
        ZendTypeReader $type_reader,
    ): iterable {
        foreach ($this->properties_info->getItemIterator($dereferencer) as $name => $item) {
            $property_info_pointer = $item->value->getAsPointer(
                ZendPropertyInfo::class,
                $type_reader->sizeOf(ZendPropertyInfo::getCTypeName()),
            );
            $property_info = $dereferencer->deref($property_info_pointer);
            yield (string)$name => $property_info;
        }
    }

    /** @return iterable<string, Zval> */
    public function getStaticPropertyIterator(
        Dereferencer $dereferencer,
        ZendTypeReader $type_reader,
        int $map_ptr_base,
    ): iterable {
        if ($this->default_static_members_count === 0 or is_null($this->static_members_table)) {
            return;
        }

        if (!isset($this->static_properties_table_cache)) {
            $property_count = $this->default_static_members_count;
            $table_size = $property_count * $type_reader->sizeOf(Zval::getCTypeName());
            $table_address = $type_reader->resolveMapPtr(
                $map_ptr_base,
                $this->static_members_table->address,
                $dereferencer,
            );
            if ($table_address === 0) {
                return;
            }
            $properties_table_pointer = new Pointer(
                ZvalArray::class,
                $table_address,
                $table_size,
            );
            $this->static_properties_table_cache = $dereferencer->deref($properties_table_pointer);
        }

        foreach ($this->iteratePropertyInfo($dereferencer, $type_reader) as $name => $property_info) {
            if (!$property_info->isStatic()) {
                continue;
            }
            $real_offset = $property_info->offset;
            yield $name => $this->static_properties_table_cache[$real_offset];
        }
    }

    public static function getCTypeName(): string
    {
        return 'zend_class_entry';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<zend_class_entry> $casted_cdata
         * @var Pointer<ZendClassEntry> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    public function getPointer(): Pointer
    {
        return $this->pointer;
    }

    public function getClassName(Dereferencer $dereferencer): string
    {
        $string = $dereferencer->deref($this->name);
        return $string->toString($dereferencer);
    }

    public function isInternal(): bool
    {
        return $this->type === 1;
    }

    public function isEnum(): bool
    {
        return (bool)($this->ce_flags & (1 << 28));
    }
}
