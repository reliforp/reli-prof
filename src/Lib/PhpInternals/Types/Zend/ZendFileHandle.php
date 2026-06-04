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

use FFI\PhpInternals\zend_file_handle;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * View of a `zend_file_handle` — Zend's handle for a compiled file. The
 * `buf` / `len` pair is the in-memory copy of the file's source that
 * `zend_stream_fixup()` reads during compilation; for the primary script
 * it is held for the whole process lifetime. `buf` is declared `char*`
 * in the header (not `void*`) so casting it to an integer address does
 * not trip FFI's void*-deref SIGSEGV.
 */
final class ZendFileHandle implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $buf;
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $len;
    /**
     * 1 = primary script, 0 = not primary, -1 = unknown (the field does
     * not exist before PHP 8.1, where the handle carried `free_filename`
     * instead). Callers use it only for labelling, never for correctness.
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public int $primary_script;

    /**
     * @param CastedCData<zend_file_handle> $casted_cdata
     * @param Pointer<ZendFileHandle> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->buf);
        unset($this->len);
        unset($this->primary_script);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'buf' => $this->buf = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->buf
            ),
            'len' => $this->len = $this->casted_cdata->casted->len,
            'primary_script' => $this->primary_script = $this->readPrimaryScript(),
        };
    }

    private function readPrimaryScript(): int
    {
        $ctype = \FFI::typeof($this->casted_cdata->casted);
        if (in_array('primary_script', $ctype->getStructFieldNames(), true)) {
            return $this->casted_cdata->casted->primary_script;
        }
        return -1;
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_file_handle';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<zend_file_handle> $casted_cdata
         * @var Pointer<ZendFileHandle> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
