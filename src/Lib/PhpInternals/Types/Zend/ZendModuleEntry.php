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
use Reli\Lib\FFI\Cast;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\C\RawString;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendModuleEntry implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public bool $zts;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<RawString>
     */
    public Pointer $version;

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
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'zts' => $this->zts = (bool)$this->casted_cdata->casted->zts,
            'version' => $this->version = new Pointer(
                RawString::class,
                Cast::castPointerToInt($this->casted_cdata->casted->version),
                3,
            ),
        };
    }

    public function getVersion(Dereferencer $dereferencer): string
    {
        return (string)$dereferencer->deref($this->version);
    }

    public static function getCTypeName(): string
    {
        return 'zend_module_entry';
    }

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

    public function getPointer(): Pointer
    {
        return $this->pointer;
    }



	public function isZts(): bool
	{
		return $this->zts;
	}
}
