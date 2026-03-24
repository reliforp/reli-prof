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

namespace Reli\Lib\Process\Pointer;

final class LazyDereferencer implements Dereferencer
{
    public function __construct(
        private Dereferencer $inner,
        private FieldReader $field_reader,
    ) {
    }

    /**
     * @template T of Dereferencable
     * @param Pointer<T> $pointer
     * @return T
     */
    #[\Override]
    public function deref(Pointer $pointer): mixed
    {
        if (is_a($pointer->type, LazyDereferencable::class, true)) {
            /** @var class-string<LazyDereferencable&T> $type */
            $type = $pointer->type;
            /**
             * @psalm-suppress ArgumentTypeCoercion
             * @var T
             */
            return $type::fromLazy($this->field_reader, $pointer);
        }
        return $this->inner->deref($pointer);
    }
}
