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

namespace Reli\Lib\PhpInternals\Types\Php;

use FFI\PhpInternals\php_shutdown_function_entry;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\Zend\ZendObject;
use Reli\Lib\PhpInternals\Types\Zend\Zval;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PhpShutdownFunctionEntry implements CDataDereferencable
{
    /** @param CastedCData<php_shutdown_function_entry> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
    }

    /**
     * v70-v80: function_name is an inline zval at offset 0
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedArgumentTypeCoercion
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function getFunctionNameDirect(): Zval
    {
        return Zval::fromCastedCData(
            $this->casted_cdata->createSubView($this->casted_cdata->casted->function_name),
            new Pointer(
                Zval::class,
                $this->pointer->address
                + \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('function_name'),
                \FFI::sizeof($this->casted_cdata->casted->function_name),
            ),
        );
    }

    /**
     * v81-v84: function_name is in fci.function_name
     * @psalm-suppress MixedArgument
     * @psalm-suppress MixedArgumentTypeCoercion
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress MixedPropertyFetch
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function getFunctionNameFromFci(): Zval
    {
        return Zval::fromCastedCData(
            $this->casted_cdata->createSubView($this->casted_cdata->casted->fci->function_name),
            new Pointer(
                Zval::class,
                $this->pointer->address
                + \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset('fci')
                + \FFI::typeof($this->casted_cdata->casted->fci)->getStructFieldOffset('function_name'),
                \FFI::sizeof($this->casted_cdata->casted->fci->function_name),
            ),
        );
    }

    /**
     * v85: callable is in fci_cache.closure (ZendObject pointer)
     * @return Pointer<ZendObject>|null
     * @psalm-suppress MixedArgument
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress MixedPropertyFetch
     */
    public function getClosureFromFciCache(): ?Pointer
    {
        if ($this->casted_cdata->casted->fci_cache->closure === null) {
            return null;
        }
        return Pointer::fromCData(
            ZendObject::class,
            $this->casted_cdata->casted->fci_cache->closure,
        );
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'php_shutdown_function_entry';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /** @var CastedCData<php_shutdown_function_entry> $casted_cdata */
        return new self($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
