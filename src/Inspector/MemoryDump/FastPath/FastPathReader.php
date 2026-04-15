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

namespace Reli\Inspector\MemoryDump\FastPath;

/**
 * Facade for reading Zend structures from dump regions without FFI.
 *
 * Wraps the per-version generated Reader classes behind a unified
 * API that the collector jobs can call without knowing which PHP
 * version the dump belongs to.
 *
 * @psalm-suppress MixedReturnStatement, MixedInferredReturnType, MixedMethodCall, MixedFunctionCall
 */
final class FastPathReader
{
    /** @var callable(string, int): int */
    private $fn_zval_type;
    /** @var callable(string, int): int */
    private $fn_zval_value_ptr;
    /** @var callable(string, int): int */
    private $fn_zval_value_lval;
    /** @var callable(string, int): int */
    private $fn_zval_type_info;

    /** @var callable(string, int): int */
    private $fn_bucket_val_type;
    /** @var callable(string, int): int */
    private $fn_bucket_val_value_ptr;
    /** @var callable(string, int): int */
    private $fn_bucket_key;
    /** @var callable(string, int): int */
    private $fn_bucket_h;

    /** @var callable(string, int): int */
    private $fn_array_ar_data;
    /** @var callable(string, int): int */
    private $fn_array_n_num_used;
    /** @var callable(string, int): int */
    private $fn_array_n_num_of_elements;
    /** @var callable(string, int): int */
    private $fn_array_n_table_size;
    /** @var callable(string, int): int */
    private $fn_array_refcount;
    /** @var callable(string, int): int */
    private $fn_array_type_info;

    /** @var callable(string, int): int */
    private $fn_string_len;
    /** @var callable(string, int): int */
    private $fn_string_h;
    /** @var callable(string, int): int */
    private $fn_string_refcount;
    /** @var callable(string, int): int */
    private $fn_string_type_info;

    /** @var callable(string, int): int */
    private $fn_object_ce;
    /** @var callable(string, int): int */
    private $fn_object_handle;
    /** @var callable(string, int): int */
    private $fn_object_handlers;
    /** @var callable(string, int): int */
    private $fn_object_properties_table;
    /** @var callable(string, int): int */
    private $fn_object_refcount;

    private int $zval_size;
    private int $bucket_size;
    private int $array_size;
    private int $string_header_size;
    private int $object_header_size;

    /**
     * @psalm-suppress MixedAssignment
     */
    public function __construct(
        private RegionByteProvider $regions,
        string $php_version,
    ) {
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$php_version}";

        // Load the generated function files
        $gen_dir = __DIR__ . "/Generated/{$php_version}";
        foreach (['ZvalReader', 'BucketReader', 'ZendArrayReader', 'ZendStringReader', 'ZendObjectReader', 'ZendRefcountedReader'] as $f) {
            $path = "{$gen_dir}/{$f}.php";
            if (is_file($path)) {
                require_once $path;
            }
        }

        // Bind function references
        $this->fn_zval_type = "{$ns}\\zval_u1_v_type";
        $this->fn_zval_value_ptr = "{$ns}\\zval_value_ptr";
        $this->fn_zval_value_lval = "{$ns}\\zval_value_lval";
        $this->fn_zval_type_info = "{$ns}\\zval_u1_type_info";

        $this->fn_bucket_val_type = "{$ns}\\bucket_val_u1_v_type";
        $this->fn_bucket_val_value_ptr = "{$ns}\\bucket_val_value_ptr";
        $this->fn_bucket_key = "{$ns}\\bucket_key";
        $this->fn_bucket_h = "{$ns}\\bucket_h";

        $this->fn_array_ar_data = "{$ns}\\zendarray_ar_data";
        $this->fn_array_n_num_used = "{$ns}\\zendarray_n_num_used";
        $this->fn_array_n_num_of_elements = "{$ns}\\zendarray_n_num_of_elements";
        $this->fn_array_n_table_size = "{$ns}\\zendarray_n_table_size";
        $this->fn_array_refcount = "{$ns}\\zendarray_gc_refcount";
        $this->fn_array_type_info = "{$ns}\\zendarray_gc_type_info";

        $this->fn_string_len = "{$ns}\\zendstring_len";
        $this->fn_string_h = "{$ns}\\zendstring_h";
        $this->fn_string_refcount = "{$ns}\\zendstring_gc_refcount";
        $this->fn_string_type_info = "{$ns}\\zendstring_gc_type_info";

        $this->fn_object_ce = "{$ns}\\zendobject_ce";
        $this->fn_object_handle = "{$ns}\\zendobject_handle";
        $this->fn_object_handlers = "{$ns}\\zendobject_handlers";
        $this->fn_object_properties_table = "{$ns}\\zendobject_properties_table";
        $this->fn_object_refcount = "{$ns}\\zendobject_gc_refcount";

        /** @var int */
        $this->zval_size = constant("{$ns}\\ZvalLayout::SIZE");
        /** @var int */
        $this->bucket_size = constant("{$ns}\\BucketLayout::SIZE");
        /** @var int */
        $this->array_size = constant("{$ns}\\ZendArrayLayout::SIZE");
        /** @var int */
        $this->string_header_size = constant("{$ns}\\ZendStringLayout::SIZE");
        /** @var int */
        $this->object_header_size = constant("{$ns}\\ZendObjectLayout::SIZE");
    }

    // ---- Zval ----

    public function zvalType(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->zval_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_zval_type)($region->bytes, $region->offsetOf($address));
    }

    public function zvalValuePtr(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->zval_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_zval_value_ptr)($region->bytes, $region->offsetOf($address));
    }

    public function zvalValueLval(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->zval_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_zval_value_lval)($region->bytes, $region->offsetOf($address));
    }

    public function zvalTypeInfo(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->zval_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_zval_type_info)($region->bytes, $region->offsetOf($address));
    }

    // ---- Bucket ----

    public function bucketValType(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->bucket_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_bucket_val_type)($region->bytes, $region->offsetOf($address));
    }

    public function bucketValValuePtr(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->bucket_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_bucket_val_value_ptr)($region->bytes, $region->offsetOf($address));
    }

    public function bucketKeyPtr(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->bucket_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_bucket_key)($region->bytes, $region->offsetOf($address));
    }

    public function bucketH(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->bucket_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_bucket_h)($region->bytes, $region->offsetOf($address));
    }

    // ---- ZendArray ----

    public function arrayArData(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_array_ar_data)($region->bytes, $region->offsetOf($address));
    }

    public function arrayNNumUsed(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_array_n_num_used)($region->bytes, $region->offsetOf($address));
    }

    public function arrayNNumOfElements(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_array_n_num_of_elements)($region->bytes, $region->offsetOf($address));
    }

    public function arrayNTableSize(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_array_n_table_size)($region->bytes, $region->offsetOf($address));
    }

    public function arrayRefcount(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_array_refcount)($region->bytes, $region->offsetOf($address));
    }

    public function arrayTypeInfo(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_array_type_info)($region->bytes, $region->offsetOf($address));
    }

    // ---- ZendString ----

    public function stringLen(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->string_header_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_string_len)($region->bytes, $region->offsetOf($address));
    }

    public function stringH(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->string_header_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_string_h)($region->bytes, $region->offsetOf($address));
    }

    public function stringVal(int $address, int $len): ?string
    {
        $val_offset = $this->string_header_size;
        $total = $val_offset + $len;
        $region = $this->regions->regionFor($address, $total);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return substr($region->bytes, $off + $val_offset, $len);
    }

    public function stringRefcount(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->string_header_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_string_refcount)($region->bytes, $region->offsetOf($address));
    }

    public function stringTypeInfo(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->string_header_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_string_type_info)($region->bytes, $region->offsetOf($address));
    }

    // ---- ZendObject ----

    public function objectCe(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->object_header_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_object_ce)($region->bytes, $region->offsetOf($address));
    }

    public function objectHandle(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->object_header_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_object_handle)($region->bytes, $region->offsetOf($address));
    }

    public function objectHandlers(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->object_header_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_object_handlers)($region->bytes, $region->offsetOf($address));
    }

    public function objectPropertiesTable(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->object_header_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_object_properties_table)($region->bytes, $region->offsetOf($address));
    }

    public function objectRefcount(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->object_header_size);
        if ($region === null) {
            return null;
        }
        return ($this->fn_object_refcount)($region->bytes, $region->offsetOf($address));
    }

    // ---- Sizes ----

    public function zvalSize(): int
    {
        return $this->zval_size;
    }

    public function bucketSize(): int
    {
        return $this->bucket_size;
    }

    public function arraySize(): int
    {
        return $this->array_size;
    }

    public function stringHeaderSize(): int
    {
        return $this->string_header_size;
    }

    public function objectHeaderSize(): int
    {
        return $this->object_header_size;
    }
}
