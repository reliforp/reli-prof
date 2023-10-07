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
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendClassEntry implements Dereferencable
{
    public int $type;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendString>
     */
    public Pointer $name;

    public int $default_static_members_count;

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

    public int $ce_flags;

    private ?ZvalArray $static_properties_table_cache = null;

    /** @param CastedCData<zend_class_entry> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
    ) {
        unset($this->type);
        unset($this->name);
        unset($this->default_static_members_count);
        unset($this->static_members_table);
        unset($this->function_table);
        unset($this->constants_table);
        unset($this->ce_flags);
        unset($this->properties_info);
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
                )
            ),
            'constants_table' => $this->constants_table = new ZendArray(
                new CastedCData(
                    $this->casted_cdata->casted->constants_table,
                    $this->casted_cdata->casted->constants_table,
                )
            ),
            'ce_flags' => $this->ce_flags = $this->casted_cdata->casted->ce_flags,
            'properties_info' => $this->properties_info = new ZendArray(
                new CastedCData(
                    $this->casted_cdata->casted->properties_info,
                    $this->casted_cdata->casted->properties_info,
                )
            ),
        };
    }

    public function getStaticPropertyIterator(
        Dereferencer $dereferencer,
        ZendTypeReader $type_reader,
    ): iterable {
        if ($this->default_static_members_count === 0 or is_null($this->static_members_table)) {
            return;
        }

        if (!isset($this->static_properties_table_cache)) {
            $property_count = $this->default_static_members_count;
            $table_size = $property_count * $type_reader->sizeOf(Zval::getCTypeName());
            $properties_table_pointer = new Pointer(
                ZvalArray::class,
                $this->static_members_table->address,
                $table_size,
            );
            $this->static_properties_table_cache = $dereferencer->deref($properties_table_pointer);
        }

        foreach ($this->properties_info->getItemIterator($dereferencer) as $name => $item) {
            $property_info_pointer = $item->value->getAsPointer(
                ZendPropertyInfo::class,
                $type_reader->sizeOf(ZendPropertyInfo::getCTypeName()),
            );
            $property_info = $dereferencer->deref($property_info_pointer);
            if (!$property_info->isStatic()) {
                continue;
            }
            $real_offset = $property_info->offset;
            yield $name => ($this->static_properties_table_cache[$real_offset / 16]);
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
        /** @var CastedCData<zend_class_entry> $casted_cdata */
        return new self($casted_cdata);
    }

    public function getClassName(Dereferencer $dereferencer): string
    {
        $string = $dereferencer->deref($this->name);
        $val = $string->getValuePointer($this->name);
        return (string)$dereferencer->deref($val);
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
