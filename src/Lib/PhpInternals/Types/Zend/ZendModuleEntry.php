<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\PhpInternals\Types\Zend;

use FFI\PhpInternals\zend_module_entry;
use PhpProfiler\Lib\FFI\Cast;
use PhpProfiler\Lib\PhpInternals\CastedCData;
use PhpProfiler\Lib\PhpInternals\Types\C\RawString;
use PhpProfiler\Lib\Process\Pointer\Dereferencable;
use PhpProfiler\Lib\Process\Pointer\Dereferencer;
use PhpProfiler\Lib\Process\Pointer\Pointer;

final class ZendModuleEntry implements Dereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public bool $zts;
    /**
     * @psalm-suppress PropertyNotSetInConstructor
     * @var Pointer<RawString>
     */
    public Pointer $version;

    /** @param CastedCData<zend_module_entry> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
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
        /** @var CastedCData<zend_module_entry> $casted_cdata */
        return new self($casted_cdata);
    }
}
