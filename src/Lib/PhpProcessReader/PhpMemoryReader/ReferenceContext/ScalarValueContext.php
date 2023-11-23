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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext;

class ScalarValueContext implements ReferenceContext
{
    use ReferenceContextDefault;

    public function __construct(
        public bool|int|float|null|array $value,
    ) {
        if (is_float($value)) {
            if (\is_infinite($value)) {
                $this->value = ['infinity'];
            } elseif (\is_nan($value)) {
                $this->value = ['nan'];
            }
        }
    }

    public function getContexts(): iterable
    {
        return [
            'value' => $this->value,
        ];
    }
}
