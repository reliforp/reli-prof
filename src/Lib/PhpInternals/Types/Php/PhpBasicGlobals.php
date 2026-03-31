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

use FFI\PhpInternals\php_basic_globals;
use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\PhpInternals\Types\Zend\ZendArray;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Pointer;

final class PhpBasicGlobals implements CDataDereferencable
{
    /** @var Pointer<ZendArray>|null */
    public ?Pointer $user_shutdown_function_names;

    /** @param CastedCData<php_basic_globals> $casted_cdata */
    public function __construct(
        private CastedCData $casted_cdata,
        private Pointer $pointer,
    ) {
        unset($this->user_shutdown_function_names);
    }

    /**
     * @psalm-suppress UndefinedPropertyFetch
     * @psalm-suppress MixedArgument
     */
    public function __get(string $field_name): mixed
    {
        return match ($field_name) {
            'user_shutdown_function_names' => $this->user_shutdown_function_names =
                $this->casted_cdata->casted->user_shutdown_function_names !== null
                    ? Pointer::fromCData(
                        ZendArray::class,
                        $this->casted_cdata->casted->user_shutdown_function_names,
                    )
                    : null
            ,
        };
    }

    #[\Override]
    public static function getCTypeName(): string
    {
        return 'php_basic_globals';
    }

    #[\Override]
    public static function fromCastedCData(CastedCData $casted_cdata, Pointer $pointer): static
    {
        /** @var CastedCData<php_basic_globals> $casted_cdata */
        return new self($casted_cdata, $pointer);
    }

    #[\Override]
    public function getPointer(): Pointer
    {
        return $this->pointer;
    }
}
