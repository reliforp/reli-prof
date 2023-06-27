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

namespace Reli\Converter\Callgrind;

final class CallEntry
{
    public int $samples = 0;

    public function __construct(
        public FunctionEntry $callee,
        public int $caller_lineno,
    ) {
    }
}
