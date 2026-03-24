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
use Reli\Lib\Process\Pointer\Dereferencable;
use Reli\Lib\Process\Pointer\Pointer;
use Reli\Lib\Process\Pointer\PointedTypeResolver;

trait InlineCDataCreatorTrait
{
    private ?PointedTypeResolver $pointed_type_resolver = null;

    #[\Override]
    public function setPointedTypeResolver(PointedTypeResolver $resolver): void
    {
        $this->pointed_type_resolver = $resolver;
    }

    /**
     * @template T of Dereferencable
     * @param class-string<T> $target_class
     * @return T
     * @psalm-suppress RedundantConditionGivenDocblockType
     */
    private function createInlineDereferencable(string $field_name, string $target_class): Dereferencable
    {
        assert($this->casted_cdata !== null);
        /** @var \FFI\CData $field_cdata */
        $field_cdata = $this->casted_cdata->casted->$field_name;
        $resolved_class = $this->pointed_type_resolver !== null
            ? $this->pointed_type_resolver->resolve($target_class)
            : $target_class;
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
