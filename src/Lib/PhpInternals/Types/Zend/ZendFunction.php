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

namespace PhpProfiler\Lib\PhpInternals\Types\Zend;

use FFI\PhpInternals\zend_function;
use PhpProfiler\Lib\PhpInternals\CastedCData;
use PhpProfiler\Lib\Process\Pointer\Dereferencable;
use PhpProfiler\Lib\Process\Pointer\Dereferencer;
use PhpProfiler\Lib\Process\Pointer\Pointer;

final class ZendFunction implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $type;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public ZendOpArray $op_array;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendString>|null
     */
    public ?Pointer $function_name;

    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendClassEntry>|null
     */
    public ?Pointer $scope;

    /**
     * @param CastedCData<zend_function> $casted_cdata
     */
    public function __construct(
        private CastedCData $casted_cdata,
    ) {
        unset($this->type);
        unset($this->function_name);
        unset($this->scope);
        unset($this->op_array);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'type' => $this->type = $this->casted_cdata->casted->type,
            'function_name' => $this->function_name
                = $this->casted_cdata->casted->common->function_name !== null
                    ? Pointer::fromCData(
                        ZendString::class,
                        $this->casted_cdata->casted->common->function_name,
                    )
                    : null
            ,
            'scope' => $this->scope
                = $this->casted_cdata->casted->common->scope !== null
                    ? Pointer::fromCData(
                        ZendClassEntry::class,
                        $this->casted_cdata->casted->common->scope,
                    )
                    : null
            ,
            'op_array' => $this->op_array = new ZendOpArray($this->casted_cdata->casted->op_array),
        };
    }

    public static function getCTypeName(): string
    {
        return 'zend_function';
    }

    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /** @var CastedCData<zend_function> $casted_cdata */
        return new self($casted_cdata);
    }

    public function getFullyQualifiedFunctionName(Dereferencer $dereferencer): string
    {
        $class_name = $this->getClassName($dereferencer);
        $function_name = $this->getFunctionName($dereferencer) ?? '';
        if (!is_null($class_name)) {
            return $class_name . '::' . $function_name;
        }
        return $function_name;
    }

    private ?string $resolved_name_cache = null;

    public function getFunctionName(Dereferencer $dereferencer): ?string
    {
        if ($this->function_name === null) {
            return null;
        }
        if (!isset($this->resolved_name_cache)) {
            $string = $dereferencer->deref($this->function_name);
            $val = $string->getValuePointer($this->function_name);
            $this->resolved_name_cache = (string)$dereferencer->deref($val);
        }
        return $this->resolved_name_cache;
    }

    private ?string $resolved_class_name_cache = null;

    public function getClassName(Dereferencer $dereferencer): ?string
    {
        if ($this->scope === null) {
            return null;
        }
        if (!isset($this->resolved_class_name_cache)) {
            $class_entry = $dereferencer->deref($this->scope);
            $this->resolved_class_name_cache = $class_entry->getClassName($dereferencer);
        }
        return $this->resolved_class_name_cache;
    }

    private ?string $resolved_file_name_cache = null;

    public function getFileName(Dereferencer $dereferencer): ?string
    {
        if (!isset($this->resolved_file_name_cache)) {
            if ($this->type !== 2) {
                $this->resolved_file_name_cache = '<internal>';
            }
            $this->resolved_file_name_cache = $this->op_array->getFileName($dereferencer);
        }
        return $this->resolved_file_name_cache;
    }
}
