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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader;

use Reli\Lib\Log\Log;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\PhpInternals\Types\Zend\ZendClassConstant;
use Reli\Lib\PhpInternals\Types\Zend\ZendClassEntry;
use Reli\Lib\PhpInternals\Types\Zend\ZendCompilerGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendConstant;
use Reli\Lib\PhpInternals\Types\Zend\ZendString;
use Reli\Lib\PhpInternals\Types\Zend\ZendExecutorGlobals;
use Reli\Lib\PhpInternals\Types\Zend\ZendFunction;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Lightweight pointer-following walker for dump-time metadata capture.
 *
 * Walks the engine-level root HashTables (function_table, class_table,
 * zend_constants, included_files, module_registry) from the stopped
 * target process via the live Dereferencer. For each bucket value it
 * checks whether the target address is already covered by one of the
 * bulk-dumped region intervals (ZendMM chunks, huge allocs, opcache
 * SHM, PHP binary RW). If not, the address and its known size are
 * added to the "peek set" which the dumper will read and include in
 * the dump file.
 *
 * The walker does NOT recurse into user data (objects, arrays, strings
 * allocated during the request). Those live in ZendMM and are covered
 * by the chunk bulk scan. The walker's scope is the MINIT-time
 * metadata that extensions register via pemalloc(..., 1) and that
 * the analyser needs to resolve class/function/constant definitions.
 *
 * Cost: bounded by the number of entries in the root HashTables,
 * which is determined by the PHP build + loaded extensions and is
 * independent of user-data size. Typically ~8 k items / ~750 KiB.
 */
final class MetadataPeekWalker
{
    /**
     * Walk root HashTables and collect addresses outside the covered
     * intervals.
     *
     * @param list<array{address: int, size: int}> $covered_intervals
     *        Sorted disjoint intervals that the bulk scan already
     *        covers (ZendMM chunks + huge + SHM + PHP binary RW).
     * @return list<array{address: int, size: int}>
     */
    public function walk(
        Dereferencer $dereferencer,
        ZendTypeReader $type_reader,
        int $eg_address,
        int $cg_address,
        array $covered_intervals,
    ): array {
        /** @var list<array{address: int, size: int}> $peeks */
        $peeks = [];

        try {
            $eg = $dereferencer->deref(new Pointer(
                ZendExecutorGlobals::class,
                $eg_address,
                $type_reader->sizeOf('zend_executor_globals'),
            ));
        } catch (\Throwable $e) {
            Log::info('MetadataPeekWalker: failed to read EG: ' . $e->getMessage());
            return $peeks;
        }

        $func_size = $type_reader->sizeOf('zend_function');
        $ce_size = $type_reader->sizeOf('zend_class_entry');
        $const_size = $type_reader->sizeOf('zend_constant');

        // function_table
        if ($eg->function_table !== null) {
            $this->walkRootTable(
                $eg->function_table,
                $dereferencer,
                $covered_intervals,
                $peeks,
                $func_size,
                function (int $addr) use (
                    $dereferencer,
                    $type_reader,
                    $covered_intervals,
                    &$peeks,
                    $func_size,
                ): void {
                    $this->peekFunctionExternals(
                        $addr,
                        $func_size,
                        $dereferencer,
                        $type_reader,
                        $covered_intervals,
                        $peeks,
                    );
                },
            );
        }

        // class_table
        if ($eg->class_table !== null) {
            $this->walkRootTable(
                $eg->class_table,
                $dereferencer,
                $covered_intervals,
                $peeks,
                $ce_size,
                function (int $addr) use (
                    $dereferencer,
                    $type_reader,
                    $covered_intervals,
                    &$peeks,
                    $ce_size,
                ): void {
                    $this->peekClassExternals(
                        $addr,
                        $ce_size,
                        $dereferencer,
                        $type_reader,
                        $covered_intervals,
                        $peeks,
                    );
                },
            );
        }

        // zend_constants — follow value strings for internal constants
        if ($eg->zend_constants !== null) {
            $this->walkRootTable(
                $eg->zend_constants,
                $dereferencer,
                $covered_intervals,
                $peeks,
                $const_size,
                function (int $addr) use (
                    $dereferencer,
                    $type_reader,
                    $covered_intervals,
                    &$peeks,
                    $const_size,
                ): void {
                    $this->peekConstantValue(
                        $addr,
                        $const_size,
                        $dereferencer,
                        $covered_intervals,
                        $peeks,
                    );
                },
            );
        }

        // included_files — keys are filenames (zend_string*), values
        // are just IS_TRUE. We only care about the key strings.
        $this->walkInlineTableKeys(
            $eg->included_files,
            $dereferencer,
            $covered_intervals,
            $peeks,
        );

        // interned_strings (via CG)
        try {
            $cg = $dereferencer->deref(new Pointer(
                ZendCompilerGlobals::class,
                $cg_address,
                $type_reader->sizeOf('zend_compiler_globals'),
            ));
            $this->walkInlineTableKeys(
                $cg->interned_strings,
                $dereferencer,
                $covered_intervals,
                $peeks,
            );
        } catch (\Throwable $e) {
            Log::info('MetadataPeekWalker: failed to read CG: ' . $e->getMessage());
        }

        return $peeks;
    }

    /**
     * Walk a root HashTable where bucket values are IS_PTR pointing
     * to structs of `$target_size` bytes.
     *
     * @param Pointer<ZendArray> $table_pointer
     * @param list<array{address: int, size: int}> $covered
     * @param list<array{address: int, size: int}> $peeks
     * @param (callable(int): void)|null $follow
     */
    private function walkRootTable(
        Pointer $table_pointer,
        Dereferencer $dereferencer,
        array $covered,
        array &$peeks,
        int $target_size,
        ?callable $follow,
    ): void {
        try {
            $table = $dereferencer->deref($table_pointer);
        } catch (\Throwable) {
            return;
        }

        // The HashTable struct itself is typically pemalloc'd into
        // [heap] for internal tables. Peek it if not covered.
        $ht_addr = $table_pointer->address;
        $ht_size = $table_pointer->size;
        if (!self::isCovered($ht_addr, $ht_size, $covered)) {
            $peeks[] = ['address' => $ht_addr, 'size' => $ht_size];
        }

        // The arData itself might be in [heap] (pemalloc'd for
        // internal tables). Peek it if not covered.
        if ($table->arData !== null) {
            $ar_addr = $table->arData->address;
            $ar_size = $table->nNumUsed * $table->arData->size;
            if ($ar_size > 0 && !self::isCovered($ar_addr, $ar_size, $covered)) {
                $peeks[] = ['address' => $ar_addr, 'size' => $ar_size];
            }
        }

        foreach ($table->getBucketIterator($dereferencer) as $bucket) {
            if ($bucket->val->isUndef()) {
                continue;
            }
            // All root tables store IS_PTR values; the raw pointer
            // address is the first 8 bytes of the zval value union.
            $ptr_addr = $bucket->val->value->lval;
            if ($ptr_addr === 0) {
                continue;
            }
            if (self::isCovered($ptr_addr, $target_size, $covered)) {
                continue;
            }
            $peeks[] = ['address' => $ptr_addr, 'size' => $target_size];

            // Optionally follow one level deeper into the struct.
            if ($follow !== null) {
                try {
                    $follow($ptr_addr);
                } catch (\Throwable) {
                    // Best-effort: if we can't read sub-fields, the
                    // main struct is already in the peek set.
                }
            }

            // Bucket key is often a zend_string for named entries.
            if ($bucket->key !== null) {
                $this->peekStringIfExternal(
                    $bucket->key->address,
                    $bucket->key->size,
                    $dereferencer,
                    $covered,
                    $peeks,
                );
            }
        }
    }

    /**
     * Walk a root HashTable where we care about the KEYS (zend_string
     * pointers), not the values. Used for included_files and
     * interned_strings.
     *
     * @param list<array{address: int, size: int}> $covered
     * @param list<array{address: int, size: int}> $peeks
     */
    private function walkInlineTableKeys(
        ZendArray $table,
        Dereferencer $dereferencer,
        array $covered,
        array &$peeks,
    ): void {
        if ($table->arData !== null) {
            $ar_addr = $table->arData->address;
            $ar_size = $table->nNumUsed * $table->arData->size;
            if ($ar_size > 0 && !self::isCovered($ar_addr, $ar_size, $covered)) {
                $peeks[] = ['address' => $ar_addr, 'size' => $ar_size];
            }
        }

        foreach ($table->getBucketIterator($dereferencer) as $bucket) {
            if ($bucket->val->isUndef()) {
                continue;
            }
            if ($bucket->key !== null) {
                $this->peekStringIfExternal(
                    $bucket->key->address,
                    $bucket->key->size,
                    $dereferencer,
                    $covered,
                    $peeks,
                );
            }
        }
    }

    /**
     * For an internal function at $addr, peek its function_name and
     * arg_info if they are outside the covered set.
     *
     * @param list<array{address: int, size: int}> $covered
     * @param list<array{address: int, size: int}> $peeks
     */
    /**
     * For an internal constant at $addr, peek its value if it is a
     * string outside the covered set. Without this, the analyzer's
     * EmitGlobalConstantsJob fails on the first constant whose value
     * string body is missing (opcache OFF → all in [heap]).
     *
     * @param list<array{address: int, size: int}> $covered
     * @param list<array{address: int, size: int}> $peeks
     * @psalm-suppress MixedPropertyFetch, MixedMethodCall, MixedArgument, MixedArgumentTypeCoercion
     */
    private function peekConstantValue(
        int $addr,
        int $const_size,
        Dereferencer $dereferencer,
        array $covered,
        array &$peeks,
    ): void {
        try {
            $constant = $dereferencer->deref(new Pointer(
                ZendConstant::class,
                $addr,
                $const_size,
            ));
            // name string (may differ from bucket key in some versions)
            if ($constant->name !== null) {
                $this->peekStringIfExternal(
                    $constant->name->address,
                    $constant->name->size,
                    $dereferencer,
                    $covered,
                    $peeks,
                );
            }
            // value: if IS_STRING, peek the string body
            if ($constant->value->isString() && $constant->value->value->str !== null) {
                $this->peekStringIfExternal(
                    $constant->value->value->str->address,
                    $constant->value->value->str->size,
                    $dereferencer,
                    $covered,
                    $peeks,
                );
            }
        } catch (\Throwable) {
        }
    }

    /** @psalm-suppress ArgumentTypeCoercion */
    private function peekFunctionExternals(
        int $addr,
        int $func_size,
        Dereferencer $dereferencer,
        ZendTypeReader $type_reader,
        array $covered,
        array &$peeks,
    ): void {
        try {
            $func = $dereferencer->deref(new Pointer(
                ZendFunction::class,
                $addr,
                $func_size,
            ));
        } catch (\Throwable) {
            return;
        }

        // function_name (zend_string*)
        if ($func->function_name !== null) {
            $this->peekStringIfExternal(
                $func->function_name->address,
                $func->function_name->size,
                $dereferencer,
                $covered,
                $peeks,
            );
        }

        // arg_info peeking is deferred: the arg_info Pointer field is
        // not exposed as a typed property on ZendFunction in all PHP
        // versions. The analyser graceful-skips missing arg_info via
        // MemoryAddressNotInDumpException if they happen to live in
        // [heap].
    }

    /**
     * For an internal class entry at $addr, peek its sub-HashTable
     * arData arrays and name string if they are outside the covered
     * set.
     *
     * @param list<array{address: int, size: int}> $covered
     * @param list<array{address: int, size: int}> $peeks
     */
    private function peekClassExternals(
        int $addr,
        int $ce_size,
        Dereferencer $dereferencer,
        ZendTypeReader $type_reader,
        array $covered,
        array &$peeks,
    ): void {
        try {
            $ce = $dereferencer->deref(new Pointer(
                ZendClassEntry::class,
                $addr,
                $ce_size,
            ));
        } catch (\Throwable) {
            return;
        }

        // class name (zend_string*)
        $this->peekStringIfExternal(
            $ce->name->address,
            $ce->name->size,
            $dereferencer,
            $covered,
            $peeks,
        );

        // Sub-HashTable arData + bucket targets + their internal string
        // pointers for methods, properties, constants.
        $func_size = $type_reader->sizeOf('zend_function');
        $this->peekInlineTableContents(
            $ce->function_table,
            $dereferencer,
            $func_size,
            $covered,
            $peeks,
            function (int $ptr) use ($dereferencer, $type_reader, $covered, &$peeks, $func_size): void {
                $this->peekFunctionExternals($ptr, $func_size, $dereferencer, $type_reader, $covered, $peeks);
            },
        );
        $prop_info_size = $type_reader->sizeOf('zend_property_info');
        $this->peekInlineTableContents(
            $ce->properties_info,
            $dereferencer,
            $prop_info_size,
            $covered,
            $peeks,
            function (int $ptr) use ($dereferencer, $prop_info_size, $covered, &$peeks): void {
                $this->peekPropertyInfoExternals($ptr, $prop_info_size, $dereferencer, $covered, $peeks);
            },
        );
        $class_const_size = $type_reader->sizeOf(
            ZendClassConstant::getCTypeName(),
        );
        $this->peekInlineTableContents(
            $ce->constants_table,
            $dereferencer,
            $class_const_size,
            $covered,
            $peeks,
            function (int $ptr) use ($dereferencer, $class_const_size, $covered, &$peeks): void {
                $this->peekClassConstantExternals($ptr, $class_const_size, $dereferencer, $covered, $peeks);
            },
        );

        // default_properties_table (array of zvals)
        if ($ce->default_properties_count > 0) {
            try {
                $default_props_size = $ce->default_properties_count
                    * $type_reader->sizeOf('zval');
                if (
                    $ce->default_properties_table !== null
                    && !self::isCovered(
                        $ce->default_properties_table->address,
                        $default_props_size,
                        $covered,
                    )
                ) {
                    $peeks[] = [
                        'address' => $ce->default_properties_table->address,
                        'size' => $default_props_size,
                    ];
                }
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param list<array{address: int, size: int}> $covered
     * @param list<array{address: int, size: int}> $peeks
     * @psalm-suppress MixedPropertyFetch, MixedArgument, MixedArgumentTypeCoercion
     */
    private function peekPropertyInfoExternals(
        int $addr,
        int $size,
        Dereferencer $dereferencer,
        array $covered,
        array &$peeks,
    ): void {
        try {
            $pi = $dereferencer->deref(new Pointer(
                \Reli\Lib\PhpInternals\Types\Zend\ZendPropertyInfo::class,
                $addr,
                $size,
            ));
            if ($pi->name !== null) {
                $this->peekStringIfExternal(
                    $pi->name->address,
                    $pi->name->size,
                    $dereferencer,
                    $covered,
                    $peeks,
                );
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @param list<array{address: int, size: int}> $covered
     * @param list<array{address: int, size: int}> $peeks
     * @psalm-suppress MixedPropertyFetch, MixedMethodCall, MixedArgument, MixedArgumentTypeCoercion
     */
    private function peekClassConstantExternals(
        int $addr,
        int $size,
        Dereferencer $dereferencer,
        array $covered,
        array &$peeks,
    ): void {
        try {
            $cc = $dereferencer->deref(new Pointer(
                ZendClassConstant::class,
                $addr,
                $size,
            ));
            if ($cc->value->isString() && $cc->value->value->str !== null) {
                $this->peekStringIfExternal(
                    $cc->value->value->str->address,
                    $cc->value->value->str->size,
                    $dereferencer,
                    $covered,
                    $peeks,
                );
            }
        } catch (\Throwable) {
        }
    }

    /**
     * For an inline HashTable (embedded inside a class_entry), peek
     * arData AND each bucket's IS_PTR target if outside the covered
     * set. Without this, the analyzer can read the HashTable header
     * and the bucket array but not the struct that each bucket value
     * points to (e.g. zend_function for methods, zend_property_info
     * for properties, zend_class_constant for class constants).
     *
     * @param list<array{address: int, size: int}> $covered
     * @param list<array{address: int, size: int}> $peeks
     * @param (callable(int): void)|null $follow_target
     */
    private function peekInlineTableContents(
        ZendArray $table,
        Dereferencer $dereferencer,
        int $target_size,
        array $covered,
        array &$peeks,
        ?callable $follow_target = null,
    ): void {
        try {
            if ($table->arData === null) {
                return;
            }
            $ar_addr = $table->arData->address;
            $ar_size = $table->nNumUsed * $table->arData->size;
            if ($ar_size > 0 && !self::isCovered($ar_addr, $ar_size, $covered)) {
                $peeks[] = ['address' => $ar_addr, 'size' => $ar_size];
            }

            // Walk bucket targets (IS_PTR values)
            foreach ($table->getBucketIterator($dereferencer) as $bucket) {
                if ($bucket->val->isUndef()) {
                    continue;
                }
                $ptr_addr = $bucket->val->value->lval;
                if ($ptr_addr !== 0 && !self::isCovered($ptr_addr, $target_size, $covered)) {
                    $peeks[] = ['address' => $ptr_addr, 'size' => $target_size];
                    if ($follow_target !== null) {
                        try {
                            $follow_target($ptr_addr);
                        } catch (\Throwable) {
                        }
                    }
                }
                // Bucket key string
                if ($bucket->key !== null) {
                    $this->peekStringIfExternal(
                        $bucket->key->address,
                        $bucket->key->size,
                        $dereferencer,
                        $covered,
                        $peeks,
                    );
                }
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Add a zend_string (header + value body) to the peek set if its
     * address is outside the covered intervals. The string header
     * alone is not enough — the analyzer's toString() reads past it
     * into the char[] body — so we deref the header to get the real
     * length and peek the full allocation.
     *
     * @param list<array{address: int, size: int}> $covered
     * @param list<array{address: int, size: int}> $peeks
     */
    private function peekStringIfExternal(
        int $addr,
        int $header_size,
        Dereferencer $dereferencer,
        array $covered,
        array &$peeks,
    ): void {
        if ($addr === 0 || $header_size <= 0) {
            return;
        }
        if (self::isCovered($addr, $header_size, $covered)) {
            return;
        }
        try {
            $str = $dereferencer->deref(new Pointer(
                ZendString::class,
                $addr,
                $header_size,
            ));
            $full_size = $str->getSize();
            $peeks[] = ['address' => $addr, 'size' => $full_size];
        } catch (\Throwable) {
            // Fallback: peek just the header; toString() may fail
            // at analyze time but the header fields are still useful.
            $peeks[] = ['address' => $addr, 'size' => $header_size];
        }
    }

    /**
     * Binary-search the sorted disjoint covered intervals to check
     * whether [$addr, $addr + $size) is fully contained in one of
     * them.
     *
     * @param list<array{address: int, size: int}> $intervals
     */
    private static function isCovered(int $addr, int $size, array $intervals): bool
    {
        $lo = 0;
        $hi = count($intervals) - 1;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $iv = $intervals[$mid];
            $iv_end = $iv['address'] + $iv['size'];
            if ($addr >= $iv['address'] && ($addr + $size) <= $iv_end) {
                return true;
            }
            if ($addr < $iv['address']) {
                $hi = $mid - 1;
            } else {
                $lo = $mid + 1;
            }
        }
        return false;
    }
}
