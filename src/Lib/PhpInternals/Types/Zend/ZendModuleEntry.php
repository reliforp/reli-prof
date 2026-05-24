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

use FFI\PhpInternals\zend_module_entry;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\C\RawString;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendModuleEntry implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public bool $zts;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<RawString>
     */
    public Pointer $version;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<RawString>
     */
    public Pointer $name;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $globals_size;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $globals_ptr;

    /**
     * @param CastedCData<zend_module_entry> $casted_cdata
     * @param Pointer<ZendModuleEntry> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->zts);
        unset($this->version);
        unset($this->name);
        unset($this->globals_size);
        unset($this->globals_ptr);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'zts' => $this->zts = (bool)$this->casted_cdata->casted->zts,
            'version' => $this->version = new Pointer(
                RawString::class,
                FFIHelper::castPointerToInt($this->casted_cdata->casted->version),
                3,
            ),
            'name' => $this->name = new Pointer(
                RawString::class,
                FFIHelper::castPointerToInt($this->casted_cdata->casted->name),
                256,
            ),
            'globals_size' => $this->globals_size
                = $this->casted_cdata->casted->globals_size,
            'globals_ptr' => $this->globals_ptr
                = FFIHelper::castPointerToInt($this->casted_cdata->casted->globals_ptr),
        };
    }

    public function getVersion(Dereferencer $dereferencer): string
    {
        return (string)$dereferencer->deref($this->version);
    }

    public function getName(Dereferencer $dereferencer): string
    {
        return (string)$dereferencer->deref($this->name);
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_module_entry';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<zend_module_entry> $casted_cdata
         * @var Pointer<ZendModuleEntry> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
