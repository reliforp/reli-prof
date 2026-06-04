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

use FFI\PhpInternals\zend_llist_element;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Minimal view of a `zend_llist_element`. Exposes the `next` link as an
 * integer address; the inline `data` payload is read by the caller at
 * `element_address + offsetof(data)`.
 */
final class ZendLlistElement implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $next;

    /**
     * @param CastedCData<zend_llist_element> $casted_cdata
     * @param Pointer<ZendLlistElement> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->next);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'next' => $this->next = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->next
            ),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_llist_element';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<zend_llist_element> $casted_cdata
         * @var Pointer<ZendLlistElement> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
