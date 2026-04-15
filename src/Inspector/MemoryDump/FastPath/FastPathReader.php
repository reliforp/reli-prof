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
    /** @var class-string */
    private string $zval_reader;
    /** @var class-string */
    private string $bucket_reader;
    /** @var class-string */
    private string $array_reader;
    /** @var class-string */
    private string $string_reader;
    /** @var class-string */
    private string $object_reader;

    private int $zval_size;
    private int $bucket_size;
    private int $array_size;
    private int $string_header_size;
    private int $object_header_size;

    /**
     * @psalm-suppress PropertyTypeCoercion — class-strings built from version
     * @psalm-suppress MixedAssignment
     */
    public function __construct(
        private RegionByteProvider $regions,
        string $php_version,
    ) {
        $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$php_version}";
        /** @psalm-suppress PropertyTypeCoercion */
        $this->zval_reader = "{$ns}\\ZvalReader";
        /** @psalm-suppress PropertyTypeCoercion */
        $this->bucket_reader = "{$ns}\\BucketReader";
        /** @psalm-suppress PropertyTypeCoercion */
        $this->array_reader = "{$ns}\\ZendArrayReader";
        /** @psalm-suppress PropertyTypeCoercion */
        $this->string_reader = "{$ns}\\ZendStringReader";
        /** @psalm-suppress PropertyTypeCoercion */
        $this->object_reader = "{$ns}\\ZendObjectReader";

        $layout_ns = $ns;
        /** @var int */
        $this->zval_size = constant("{$layout_ns}\\ZvalLayout::SIZE");
        /** @var int */
        $this->bucket_size = constant("{$layout_ns}\\BucketLayout::SIZE");
        /** @var int */
        $this->array_size = constant("{$layout_ns}\\ZendArrayLayout::SIZE");
        /** @var int */
        $this->string_header_size = constant("{$layout_ns}\\ZendStringLayout::SIZE");
        /** @var int */
        $this->object_header_size = constant("{$layout_ns}\\ZendObjectLayout::SIZE");
    }

    // ---- Zval ----

    public function zvalType(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->zval_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->zval_reader, 'readU1VType']($region->bytes, $off);
    }

    public function zvalValuePtr(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->zval_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->zval_reader, 'readValuePtr']($region->bytes, $off);
    }

    public function zvalValueLval(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->zval_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->zval_reader, 'readValueLval']($region->bytes, $off);
    }

    public function zvalTypeInfo(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->zval_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->zval_reader, 'readU1TypeInfo']($region->bytes, $off);
    }

    // ---- Bucket ----

    public function bucketValType(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->bucket_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->bucket_reader, 'readValU1VType']($region->bytes, $off);
    }

    public function bucketValValuePtr(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->bucket_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->bucket_reader, 'readValValuePtr']($region->bytes, $off);
    }

    public function bucketKeyPtr(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->bucket_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->bucket_reader, 'readKey']($region->bytes, $off);
    }

    public function bucketH(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->bucket_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->bucket_reader, 'readH']($region->bytes, $off);
    }

    // ---- ZendArray ----

    public function arrayArData(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->array_reader, 'readArData']($region->bytes, $off);
    }

    public function arrayNNumUsed(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->array_reader, 'readNNumUsed']($region->bytes, $off);
    }

    public function arrayNNumOfElements(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->array_reader, 'readNNumOfElements']($region->bytes, $off);
    }

    public function arrayNTableSize(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->array_reader, 'readNTableSize']($region->bytes, $off);
    }

    public function arrayRefcount(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->array_reader, 'readGcRefcount']($region->bytes, $off);
    }

    public function arrayTypeInfo(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->array_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->array_reader, 'readGcTypeInfo']($region->bytes, $off);
    }

    // ---- ZendString ----

    public function stringLen(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->string_header_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->string_reader, 'readLen']($region->bytes, $off);
    }

    public function stringH(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->string_header_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->string_reader, 'readH']($region->bytes, $off);
    }

    public function stringVal(int $address, int $len): ?string
    {
        $val_offset = $this->string_header_size; // val is right after the header
        $total = $val_offset + $len;
        $region = $this->regions->regionFor($address, $total);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return PrimitiveReaders::slice($region->bytes, $off + $val_offset, $len);
    }

    public function stringRefcount(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->string_header_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->string_reader, 'readGcRefcount']($region->bytes, $off);
    }

    public function stringTypeInfo(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->string_header_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->string_reader, 'readGcTypeInfo']($region->bytes, $off);
    }

    // ---- ZendObject ----

    public function objectCe(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->object_header_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->object_reader, 'readCe']($region->bytes, $off);
    }

    public function objectHandle(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->object_header_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->object_reader, 'readHandle']($region->bytes, $off);
    }

    public function objectHandlers(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->object_header_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->object_reader, 'readHandlers']($region->bytes, $off);
    }

    public function objectPropertiesTable(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->object_header_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->object_reader, 'readPropertiesTable']($region->bytes, $off);
    }

    public function objectRefcount(int $address): ?int
    {
        $region = $this->regions->regionFor($address, $this->object_header_size);
        if ($region === null) {
            return null;
        }
        $off = $region->offsetOf($address);
        return [$this->object_reader, 'readGcRefcount']($region->bytes, $off);
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
