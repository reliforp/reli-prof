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

use FFI\PhpInternals\zend_llist;
use Reli\Lib\FFI\FFIHelper;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Minimal view of a `zend_llist`. Only the `head` element pointer (as an
 * integer address) is exposed — enough to walk the list manually.
 */
final class ZendLlist implements CDataDereferencable
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public int $head;

    /**
     * @param CastedCData<zend_llist> $casted_cdata
     * @param Pointer<ZendLlist> $pointer
     */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->head);
    }

    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'head' => $this->head = FFIHelper::castPointerToInt(
                $this->casted_cdata->casted->head
            ),
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'zend_llist';
    }

    #[\Override]
    public static function fromCastedCData(
        CastedCData $casted_cdata,
        Pointer $pointer
    ): static {
        /**
         * @var CastedCData<zend_llist> $casted_cdata
         * @var Pointer<ZendLlist> $pointer
         */
        return new self($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
