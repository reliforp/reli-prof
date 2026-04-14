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

interface Dereferencer
{
    /**
     * Runtime type hint removed from $pointer to avoid ZEND_RECV
     * overhead on this ultra-hot path. The @param doc preserves
     * static analysis coverage.
     *
     * @template T of Dereferencable
     * @param Pointer<T> $pointer
     * @return T
     */
    public function deref(/* Pointer */ $pointer): mixed;
}
