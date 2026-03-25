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

use Reli\Lib\PhpInternals\CastedCData;
use Reli\Lib\Process\Pointer\CDataDereferencable;
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\PointedTypeResolver;

trait InlineCDataCreatorTrait
{
    /** @psalm-suppress PropertyNotSetInConstructor set via factory methods */
    private PointedTypeResolver $pointed_type_resolver;

    /**
     * @template T of CDataDereferencable
     * @param class-string<T> $target_class
     * @return T
     * @psalm-suppress RedundantConditionGivenDocblockType
     * @psalm-suppress InvalidReturnType psalm cannot narrow T through resolve()
     */
    private function createInlineDereferencable(string $field_name, string $target_class): CDataDereferencable
    {
        assert($this->casted_cdata !== null);
        /** @var \FFI\CData $field_cdata */
        $field_cdata = $this->casted_cdata->casted->$field_name;
        $resolved_class = $this->pointed_type_resolver->resolve($target_class);
        assert(is_a($resolved_class, CDataDereferencable::class, true));
        return $resolved_class::fromCastedCData(
            new CastedCData(
                $field_cdata,
                $field_cdata,
            ),
            new Pointer(
                $resolved_class,
                $this->pointer->address
                +
                \FFI::typeof($this->casted_cdata->casted)->getStructFieldOffset($field_name),
                \FFI::sizeof($field_cdata),
            ),
        );
    }
}
