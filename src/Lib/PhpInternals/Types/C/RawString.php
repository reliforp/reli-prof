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

namespace Reli\Lib\PhpInternals\Types\C;

use FFI\CData;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class RawString implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public string $value;

    /** @param CastedCData<CData> $cdata */
    public function __construct(
        private CastedCData $cdata,
        private int $len,
        private Pointer $pointer,
    ) {
        unset($this->value);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function __get(string $field_name): string
    {
        return match ($field_name) {
            'value' => $this->value = $this->readBounded(),
        };
    }

    /**
     * Read up to $len bytes, truncating early at the first NUL if any.
     *
     * Callers use RawString both for explicit-length zend_string::val blobs
     * and for fixed-size char[] fields whose actual content is NUL-terminated
     * somewhere within. A 1-arg FFI::string() honours the NUL but can walk
     * past $len into unmapped memory when the terminator is missing
     * (observed as intermittent SIGSEGV in integration tests); a 2-arg call
     * bounds the read but keeps trailing garbage for the NUL-terminated use.
     * Bound first, then strip at the first NUL to satisfy both.
     */
    private function readBounded(): string
    {
        $raw = \FFI::string($this->cdata->casted, $this->len);
        $nul = strpos($raw, "\0");
        return $nul === false ? $raw : substr($raw, 0, $nul);
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'char[0]';
    }

    /** @param CastedCData<CData> $casted_cdata */
    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        return new self($casted_cdata, $pointer->size, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
