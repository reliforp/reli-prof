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

namespace Reli\Lib\PhpInternals;

use FFI\CData;

/** @template-covariant T of CData */
final class CastedCData
{
    /**
     * The root owner CData that keeps the backing buffer alive for any
     * subviews derived from it. This is intentionally not guaranteed to be
     * the same object as `$casted`.
     *
     * @param CData $raw
     * @param T $casted
     */
    public function __construct(
        public object $raw,
        public object $casted,
    ) {
    }

    /**
     * Create a subview that shares the same root owner.
     *
     * Keeping subview construction behind this helper makes it easier to
     * switch the ownership mechanism later without touching every wrapper.
     *
     * @template TSubView of CData
     * @param TSubView $casted
     * @return self<TSubView>
     */
    public function createSubView(object $casted): self
    {
        /** @var self<TSubView> */
        return new self($this->raw, $casted);
    }
}
