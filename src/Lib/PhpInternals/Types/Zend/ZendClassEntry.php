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

use FFI\PhpInternals\zend_class_entry;
use PhpProfiler\Lib\PhpInternals\CastedCData;
use PhpProfiler\Lib\Process\Pointer\Dereferencable;
use PhpProfiler\Lib\Process\Pointer\Dereferencer;
use PhpProfiler\Lib\Process\Pointer\Pointer;

final class ZendClassEntry implements Dereferencable
{
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<ZendString>
     */
    public Pointer $name;

    /** @param CastedCData<zend_class_entry> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
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
        /** @var CastedCData<zend_class_entry> $casted_cdata */
        return new self($casted_cdata);
    }

    public function getClassName(Dereferencer $dereferencer): string
    {
        $string = $dereferencer->deref($this->name);
        $val = $string->getValuePointer($this->name);
        return (string)$dereferencer->deref($val);
    }
}
