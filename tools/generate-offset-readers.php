#!/usr/bin/env php
<?php

/**
 * Generate per-version Layout + Reader classes for the analyze fast path.
 *
 * Usage:
 *   php tools/generate-offset-readers.php [version...]
 *   php tools/generate-offset-readers.php          # all versions
 *   php tools/generate-offset-readers.php v74 v83  # specific versions
 *
 * Reads the existing FFI C headers to compute struct field offsets,
 * then emits PHP classes under src/Inspector/MemoryDump/FastPath/Generated/.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$all_versions = ['v70', 'v71', 'v72', 'v73', 'v74', 'v80', 'v81', 'v82', 'v83', 'v84', 'v85'];
$versions = $argc > 1 ? array_slice($argv, 1) : $all_versions;

$output_base = __DIR__ . '/../src/Inspector/MemoryDump/FastPath/Generated';

// Hot struct fields to generate.
// Format: struct_name => [
//     'c_type' => C type name for FFI,
//     'fields' => [ php_const_name => [field_path_parts, read_type] ],
// ]
//
// read_type: 'u8', 'u16le', 'u32le', 'u64le', 'ptr', 'i32le'
//
// For nested fields, provide the full path as array.
// The generator will resolve each step via FFI offset calculation.
$struct_defs = [
    'Zval' => [
        'c_type' => 'zval',
        'fields' => [
            'VALUE_PTR' => [['value', 'counted'], 'ptr'],
            'VALUE_LVAL' => [['value', 'lval'], 'u64le'],
            'VALUE_DVAL' => [['value', 'dval'], 'u64le'], // raw bits, caller interprets
            'U1_TYPE_INFO' => [['u1', 'type_info'], 'u32le'],
            'U1_V_TYPE' => [['u1', 'v', 'type'], 'u8'],
            'U1_V_TYPE_FLAGS' => [['u1', 'v', 'type_flags'], 'u8'],
            'U2_NEXT' => [['u2', 'next'], 'u32le'],
        ],
    ],
    'Bucket' => [
        'c_type' => 'Bucket',
        'fields' => [
            'VAL_VALUE_PTR' => [['val', 'value', 'counted'], 'ptr'],
            'VAL_U1_V_TYPE' => [['val', 'u1', 'v', 'type'], 'u8'],
            'VAL_U1_V_TYPE_FLAGS' => [['val', 'u1', 'v', 'type_flags'], 'u8'],
            'VAL_U1_TYPE_INFO' => [['val', 'u1', 'type_info'], 'u32le'],
            'H' => [['h'], 'u64le'],
            'KEY' => [['key'], 'ptr'],
        ],
    ],
    'ZendArray' => [
        'c_type' => 'zend_array',
        'fields' => [
            'GC_REFCOUNT' => [['gc', 'refcount'], 'u32le'],
            'GC_TYPE_INFO' => [['gc', 'u', 'type_info'], 'u32le'],
            'N_TABLE_MASK' => [['nTableMask'], 'u32le'],
            'AR_DATA' => [['arData'], 'ptr'],
            'N_NUM_USED' => [['nNumUsed'], 'u32le'],
            'N_NUM_OF_ELEMENTS' => [['nNumOfElements'], 'u32le'],
            'N_TABLE_SIZE' => [['nTableSize'], 'u32le'],
        ],
    ],
    'ZendString' => [
        'c_type' => 'zend_string',
        'fields' => [
            'GC_REFCOUNT' => [['gc', 'refcount'], 'u32le'],
            'GC_TYPE_INFO' => [['gc', 'u', 'type_info'], 'u32le'],
            'H' => [['h'], 'u64le'],
            'LEN' => [['len'], 'u64le'],
            'VAL_OFFSET' => [['val'], 'u8'], // offset of the char[1] field
        ],
    ],
    'ZendObject' => [
        'c_type' => 'zend_object',
        'fields' => [
            'GC_REFCOUNT' => [['gc', 'refcount'], 'u32le'],
            'GC_TYPE_INFO' => [['gc', 'u', 'type_info'], 'u32le'],
            'HANDLE' => [['handle'], 'u32le'],
            'CE' => [['ce'], 'ptr'],
            'HANDLERS' => [['handlers'], 'ptr'],
            'PROPERTIES_TABLE' => [['properties_table'], 'ptr'],
        ],
    ],
    'ZendRefcounted' => [
        'c_type' => 'zend_refcounted',
        'fields' => [
            'GC_REFCOUNT' => [['gc', 'refcount'], 'u32le'],
            'GC_TYPE_INFO' => [['gc', 'u', 'type_info'], 'u32le'],
        ],
    ],
];

foreach ($versions as $version) {
    $header_path = __DIR__ . "/../src/Lib/PhpInternals/Headers/{$version}.h";
    if (!file_exists($header_path)) {
        fprintf(STDERR, "Warning: header not found for %s, skipping\n", $version);
        continue;
    }

    $ffi = FFI::load($header_path);
    if ($ffi === null) {
        fprintf(STDERR, "Warning: failed to load FFI header for %s, skipping\n", $version);
        continue;
    }

    $version_dir = $output_base . '/' . $version;
    if (!is_dir($version_dir)) {
        mkdir($version_dir, 0755, true);
    }

    foreach ($struct_defs as $class_name => $def) {
        $c_type = $def['c_type'];

        // Check if the type exists in this version
        try {
            $type_obj = $ffi->type($c_type);
            if ($type_obj === null) {
                fprintf(STDERR, "  %s: type %s not available, skipping\n", $version, $c_type);
                continue;
            }
            $struct_size = FFI::sizeof($type_obj);
        } catch (\Throwable $e) {
            fprintf(STDERR, "  %s: cannot get size of %s: %s, skipping\n", $version, $c_type, $e->getMessage());
            continue;
        }

        $layout_constants = [];
        $layout_constants['SIZE'] = $struct_size;
        $reader_methods = [];

        foreach ($def['fields'] as $const_name => $field_def) {
            [$path_parts, $read_type] = $field_def;

            try {
                $offset = computeNestedOffset($ffi, $c_type, $path_parts);
                $layout_constants['OFFSET_' . $const_name] = $offset;
                $reader_methods[$const_name] = [
                    'offset_const' => 'OFFSET_' . $const_name,
                    'read_type' => $read_type,
                    'method_name' => 'read' . toPascalCase($const_name),
                ];
            } catch (\Throwable $e) {
                fprintf(
                    STDERR,
                    "  %s/%s: cannot resolve %s: %s\n",
                    $version,
                    $class_name,
                    implode('.', $path_parts),
                    $e->getMessage()
                );
            }
        }

        // Emit Layout class
        $layout_code = generateLayoutClass($version, $class_name, $layout_constants);
        file_put_contents("{$version_dir}/{$class_name}Layout.php", $layout_code);

        // Emit Reader class
        $reader_code = generateReaderClass($version, $class_name, $reader_methods);
        file_put_contents("{$version_dir}/{$class_name}Reader.php", $reader_code);

        fprintf(
            STDERR,
            "  %s/%s: %d constants, %d readers (size=%d)\n",
            $version,
            $class_name,
            count($layout_constants),
            count($reader_methods),
            $struct_size,
        );
    }
}

fprintf(STDERR, "Done.\n");

// ---- helpers ----

function computeNestedOffset(FFI $ffi, string $base_type, array $path_parts): int
{
    // Walk the field path step by step. At each level, try to get
    // the field address via FFI::addr(). If the field is a scalar
    // (int/float/null pointer), use a byte-scan fallback.
    $dummy = $ffi->new($base_type);
    if ($dummy === null) {
        throw new \RuntimeException("Cannot allocate {$base_type}");
    }

    $base_addr = addrOf($dummy);
    $current = $dummy;
    $accumulated_offset = 0;

    foreach ($path_parts as $field_name) {
        $child = $current->$field_name;

        // Try direct address
        try {
            $child_addr = addrOf($child);
            $accumulated_offset = $child_addr - $base_addr;
            $current = $child;
            continue;
        } catch (\Throwable) {
            // Scalar field — use byte scan
        }

        // Byte-scan fallback for scalar fields:
        // Fill parent with 0xFF, set field to 0, find first 0x00 byte.
        $parent_size = FFI::sizeof($current);
        $probe = FFI::new(FFI::typeof($current));
        if ($probe === null) {
            throw new \RuntimeException("Cannot allocate probe buffer");
        }
        FFI::memset($probe, 0xFF, $parent_size);

        if (is_int($child)) {
            $probe->$field_name = 0;
        } elseif (is_float($child)) {
            $probe->$field_name = 0.0;
        } else {
            // Null pointer: fill with 0x00 instead, set everything
            // to 0xFF, then the pointer stays null (0x00 bytes).
            // Actually: fill with 0x00, use memcpy to write 0xFF
            // pattern to the whole struct, then the null pointer
            // field will be overwritten too. Need different approach.
            //
            // For null pointers: fill with 0x00, write a known
            // non-zero pattern via FFI::memcpy into the whole buffer,
            // then the pointer field (which is 0x00) tells us where.
            // But FFI::memset(0xFF) also sets the pointer bytes.
            //
            // Simplest: pointers are 8-byte aligned. Fill parent
            // with alternating pattern, use memset to mark.
            FFI::memset($probe, 0x00, $parent_size);
            FFI::memset($probe, 0xFF, $parent_size);
            // Now set the pointer to null via zeroing those bytes.
            // We don't know which bytes yet — that's what we're finding.
            // Use raw string comparison: create all-0xFF, then
            // create all-0x00 and see where the pointer is by
            // setting the field from each state.
            //
            // Clean approach: $probe is all 0xFF. $probe->field
            // reads as some address (0xFFFFFFFFFFFFFFFF). A zeroed
            // probe has field = null. Compare bytes.
            $zeroed = FFI::new(FFI::typeof($current));
            if ($zeroed === null) {
                throw new \RuntimeException("Cannot allocate zeroed buffer");
            }
            FFI::memset($zeroed, 0x00, $parent_size);
            $probe_str = FFI::string($probe, $parent_size);
            $zero_str = FFI::string($zeroed, $parent_size);
            $current_addr_local = addrOf($current);
            for ($b = 0; $b < $parent_size; $b++) {
                // In zeroed buffer, pointer bytes are 0x00.
                // In 0xFF buffer, they are 0xFF.
                // But ALL bytes differ. We need to identify which
                // 8-byte span corresponds to THIS field.
                // Since we can't distinguish pointer fields from
                // other fields this way, use a different strategy.
            }
            // Give up on byte scan for pointers. Use the known
            // relationship: pointer fields in Zend structs are
            // always 8-byte aligned. Walk the struct field by field
            // using FFI type info... but we don't have field enumeration.
            //
            // Pragmatic fallback: pointers within unions at offset 0
            // (like zval.value.counted) are always at the parent's offset.
            // Other pointer fields (like zend_object.ce) can be found
            // by FFI::sizeof of preceding fields.
            //
            // Actually, the cleanest approach: use the same trick as
            // ZendTypeReader — cast the dummy to 'long' and compute.
            // But FFI::addr() fails on null pointers.
            //
            // Final answer: pointers at union level are at the
            // parent offset. For struct-level pointers, allocate a
            // char buffer, cast it, and use uintptr_t math.
            $raw_buf = FFI::new("unsigned char[{$parent_size}]");
            if ($raw_buf === null) {
                throw new \RuntimeException("Cannot allocate raw buffer");
            }
            FFI::memset($raw_buf, 0, $parent_size);
            $casted = $ffi->cast(FFI::typeof($current), $raw_buf);
            if ($casted === null) {
                throw new \RuntimeException("Cannot cast to parent type");
            }
            // Write a known address to the pointer field
            $pattern_bytes = pack('P', 0x4142434445464748);
            // We need to set $casted->$field_name but it's a pointer...
            // Instead, scan raw_buf after setting.
            // Since raw_buf is all zeros, the pointer field is null.
            // Write 0xFF to all bytes, then set the field back to null.
            FFI::memset($raw_buf, 0xFF, $parent_size);
            // Now $casted->$field_name is 0xFFFFFFFFFFFFFFFF (non-null ptr)
            // Try to set it to null... nope, can't assign null to ptr in FFI.
            //
            // OK. Different approach entirely.
            // Get the offset from the raw buffer address.
            $raw_addr = addrOf($raw_buf);
            $field_via_cast = $casted->$field_name;
            // field_via_cast is null (0x0000...) because raw_buf is zeroed.
            // After memset 0xFF, field_via_cast would be 0xFFFF...
            // But we can't get its address because it's null/non-null ptr.
            //
            // Truly final approach: write unique pattern, read back raw bytes.
            FFI::memset($raw_buf, 0, $parent_size);
            $clean = FFI::string($raw_buf, $parent_size);
            FFI::memset($raw_buf, 0, $parent_size);
            // Write unique 8-byte pattern to the field via memcpy at
            // every 8-byte offset and check which one changes the field.
            $target_pattern = 0x4142434445464748;
            for ($try_off = 0; $try_off + 8 <= $parent_size; $try_off += 8) {
                FFI::memset($raw_buf, 0, $parent_size);
                $patch = pack('P', $target_pattern);
                for ($pb = 0; $pb < 8; $pb++) {
                    $raw_buf[$try_off + $pb] = ord($patch[$pb]);
                }
                $val = $casted->$field_name;
                if ($val !== null) {
                    // Found it!
                    $accumulated_offset += $try_off;
                    return $accumulated_offset;
                }
            }
            throw new \RuntimeException(
                "Cannot find pointer offset of '{$field_name}'"
            );
        }

        $probe_str = FFI::string($probe, $parent_size);
        for ($b = 0; $b < $parent_size; $b++) {
            if ($probe_str[$b] === "\x00") {
                $accumulated_offset += $b;
                break 2; // found, and this is always the last field
            }
        }

        throw new \RuntimeException(
            "Cannot find offset of scalar '{$field_name}' in " . implode('.', $path_parts)
        );
    }

    return $accumulated_offset;
}

function addrOf(mixed $cdata): int
{
    $ptr = FFI::cast('uintptr_t', FFI::addr($cdata));
    /** @var int */
    return $ptr->cdata;
}

function toPascalCase(string $name): string
{
    return str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($name))));
}

function camelCase(string $name): string
{
    $pascal = toPascalCase($name);
    return lcfirst($pascal);
}

function generateLayoutClass(string $version, string $class_name, array $constants): string
{
    $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
    $lines = [];
    $lines[] = '<?php';
    $lines[] = '';
    $lines[] = '// AUTO-GENERATED by tools/generate-offset-readers.php — do not edit';
    $lines[] = '';
    $lines[] = 'declare(strict_types=1);';
    $lines[] = '';
    $lines[] = "namespace {$ns};";
    $lines[] = '';
    $lines[] = "final class {$class_name}Layout";
    $lines[] = '{';
    foreach ($constants as $name => $value) {
        $lines[] = "    public const {$name} = {$value};";
    }
    $lines[] = '}';
    $lines[] = '';
    return implode("\n", $lines);
}

function generateReaderClass(string $version, string $class_name, array $methods): string
{
    $ns = "Reli\\Inspector\\MemoryDump\\FastPath\\Generated\\{$version}";
    $use = "use Reli\\Inspector\\MemoryDump\\FastPath\\PrimitiveReaders;";
    $layout_class = "{$class_name}Layout";

    $lines = [];
    $lines[] = '<?php';
    $lines[] = '';
    $lines[] = '// AUTO-GENERATED by tools/generate-offset-readers.php — do not edit';
    $lines[] = '';
    $lines[] = 'declare(strict_types=1);';
    $lines[] = '';
    $lines[] = "namespace {$ns};";
    $lines[] = '';
    $lines[] = $use;
    $lines[] = '';
    $lines[] = "final class {$class_name}Reader";
    $lines[] = '{';

    foreach ($methods as $const_name => $info) {
        $method_name = $info['method_name'];
        $read_type = $info['read_type'];
        $offset_const = $info['offset_const'];

        $lines[] = "    public static function {$method_name}(string \$buf, int \$base): int";
        $lines[] = '    {';
        $lines[] = "        return PrimitiveReaders::{$read_type}(\$buf, \$base + {$layout_class}::{$offset_const});";
        $lines[] = '    }';
        $lines[] = '';
    }

    // Remove trailing blank line before closing brace
    if (end($lines) === '') {
        array_pop($lines);
    }
    $lines[] = '}';
    $lines[] = '';
    return implode("\n", $lines);
}
