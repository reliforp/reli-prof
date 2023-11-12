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
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Dereferencer;
use Reli\Lib\Process\Pointer\Pointer;

final class ZendClassEntry implements Dereferencable
{
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendString>
     */
    public Pointer $name;

    /**
     * @param CastedCData<zend_class_entry> $casted_cdata
     * @param Pointer<ZendClassEntry> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->name);
    }

    public function __get(string $field_name)
    {
        return match ($field_name) {
            'name' => $this->name = Pointer::fromCData(
                ZendString::class,
                $this->casted_cdata->casted->name,
            ),
        };
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
}
